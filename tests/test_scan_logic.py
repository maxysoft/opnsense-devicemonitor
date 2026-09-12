#!/usr/bin/env python3
"""Drives full_scan() against a synthetic hostwatch feed.

Lives outside src/ on purpose: it is not part of the package.

The scanner cannot be imported as-is because it reads its paths from an
installed defaults.json and logs to /var/log, so a copy is rewritten to point
at a temporary directory. Everything else is the real code, including the
transition detection this is here to pin down.

Two defects that shipped would have been caught by this file:
  - the transition query ran after the sqlite connection was closed, so every
    scan raised and no notification was ever dispatched,
  - narrowing the monitored interfaces reported the de-selected devices as
    having gone offline.

Run: python3 tests/test_scan_logic.py
"""

import datetime
import importlib.util
import json
import os
import shutil
import sqlite3
import sys
import tempfile
import time
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SCANNER = os.path.join(ROOT, 'src/opnsense/scripts/OPNsense/DeviceMonitor/scan_network.py')
DEFAULTS = os.path.join(ROOT, 'src/opnsense/mvc/app/models/OPNsense/DeviceMonitor/defaults.json')

CONFIG_XML = (
    "<opnsense><interfaces>"
    "<lan><if>igc1</if><descr>LAN</descr></lan>"
    "<opt2><if>vlan0.11</if><descr>IoT</descr></opt2>"
    "</interfaces>"
    "<dhcpd><lan><enable/>"
    "<staticmap><mac>aa:bb:cc:dd:ee:02</mac></staticmap>"
    "</lan></dhcpd>"
    "</opnsense>"
)


def load_scanner(workdir):
    defaults = json.load(open(DEFAULTS))
    for key, name in (('dbFile', 'devices.db'), ('configFile', 'config.json'),
                      ('hostwatchDb', 'hosts.db')):
        defaults['paths'][key] = os.path.join(workdir, name)
    defaults_path = os.path.join(workdir, 'defaults.json')
    json.dump(defaults, open(defaults_path, 'w'))

    source = open(SCANNER).read()
    source = source.replace(
        "defaultsFile = '/usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/defaults.json'",
        "defaultsFile = %r" % defaults_path,
    ).replace('/var/log/devicemonitor.log', os.path.join(workdir, 'dm.log'))
    module_path = os.path.join(workdir, 'scan_under_test.py')
    open(module_path, 'w').write(source)

    spec = importlib.util.spec_from_file_location('scan_under_test', module_path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    config_path = os.path.join(workdir, 'config.xml')
    open(config_path, 'w').write(CONFIG_XML)
    real_parse = ET.parse
    module.ET.parse = lambda p: real_parse(config_path) if p == '/conf/config.xml' else real_parse(p)
    return module


def utc_ago(minutes):
    stamp = datetime.datetime.utcnow() - datetime.timedelta(minutes=minutes)
    return stamp.strftime('%Y-%m-%d %H:%M:%S')


def main():
    workdir = tempfile.mkdtemp()
    failures = []
    try:
        scanner = load_scanner(workdir)

        # The scanner queues events and hands each one to dispatch_queued(),
        # so that is the seam: it stands in for configd and the PHP senders.
        dispatched = []
        delivery = {'ok': True}

        def fake_dispatch(channel, event, macs):
            if channel == 'email':
                dispatched.append((event, sorted(macs)))
            return (delivery['ok'], 'delivered' if delivery['ok'] else 'endpoint refused')

        scanner.dispatch_queued = fake_dispatch

        config = {
            'enabled': True, 'email_enabled': True, 'email_to': 'a@b.c',
            'email_from': 'x@y.z', 'webhook_enabled': False, 'webhook_url': '',
            'scan_interval': 300, 'notify_events': 'new,up,down',
            'monitor_interfaces': '', 'email_interfaces': '', 'webhook_interfaces': '',
        }
        scanner.load_config = lambda: dict(config)

        hosts = []
        scanner.get_hostwatch_devices = lambda: [dict(h) for h in hosts]
        scanner.get_dhcp_descriptions = lambda: {}
        scanner.get_dnsmasq_descriptions = lambda: {}

        def device(mac, iface='igc1', minutes_ago=0):
            return {
                'mac': mac, 'ip': '10.0.0.9', 'hostname': '', 'vendor': 'Acme',
                'vlan': scanner.map_interface_to_vlan(iface),
                'first_seen': utc_ago(600), 'last_seen': utc_ago(minutes_ago),
            }

        def scan():
            dispatched.clear()
            scanner.full_scan()
            return list(dispatched)

        def check(label, actual, expected):
            if actual != expected:
                failures.append('%s: expected %r, got %r' % (label, expected, actual))
                print('  FAIL %-42s %r' % (label, actual))
            else:
                print('  ok   %-42s %r' % (label, actual))

        # An unassigned interface has no OPNsense key, so the device keeps a
        # label of its own and stays in the list.
        check('unassigned interface falls back',
              scanner.map_interface_to_vlan('vlan0.99'), 'VLAN99')
        check('assigned interface resolves', scanner.map_interface_to_vlan('igc1'), 'lan')
        # A reservation with neither hostname nor descr still counts.
        check('reservation without a name is found',
              sorted(scanner.get_reserved_macs()), ['aa:bb:cc:dd:ee:02'])

        hosts[:] = [device('aa:11')]
        check('new device notifies', scan(), [('new', ['aa:11'])])
        check('unchanged device is silent', scan(), [])

        hosts[:] = [device('aa:11', minutes_ago=99)]
        check('device going offline notifies', scan(), [('down', ['aa:11'])])
        check('staying offline is silent', scan(), [])

        hosts[:] = [device('aa:11')]
        check('device coming back notifies', scan(), [('up', ['aa:11'])])

        config['notify_events'] = 'new'
        hosts[:] = [device('aa:11', minutes_ago=99)]
        check('down suppressed when unwanted', scan(), [])
        config['notify_events'] = 'new,up,down'
        hosts[:] = [device('aa:11')]
        scan()

        # Narrowing the monitored interfaces must not look like an outage.
        hosts[:] = [device('aa:11'), device('bb:22', 'vlan0.11')]
        check('device on second interface is new', scan(), [('new', ['bb:22'])])
        config['monitor_interfaces'] = 'lan'
        check('narrowing interfaces is silent', scan(), [])
        check('and stays silent next scan', scan(), [])

        rows = sqlite3.connect(os.path.join(workdir, 'devices.db')).execute(
            'SELECT mac FROM devices ORDER BY mac').fetchall()
        check('rows survive the filter', [r[0] for r in rows], ['aa:11', 'bb:22'])

        # A de-selected device that also ages out of hostwatch must stay silent.
        # Tracking only what this scan skipped missed exactly this case.
        hosts[:] = [device('aa:11')]
        check('de-selected row aging out is silent', scan(), [])

        # The GUI writes is_active when "Check online" is used. Transitions read
        # a scanner-owned column so that cannot look like an outage or a return.
        config['monitor_interfaces'] = ''
        hosts[:] = [device('aa:11'), device('bb:22', 'vlan0.11')]
        scan()
        db = sqlite3.connect(os.path.join(workdir, 'devices.db'))
        db.execute("UPDATE devices SET is_active = 0 WHERE mac = 'aa:11'")
        db.commit(); db.close()
        check('GUI ping does not fake a transition', scan(), [])

        # A failed delivery used to be lost: the flags were cleared with the
        # scan whether or not anything was actually sent. The retry schedule
        # belongs to the queue as a whole, so one timer holds all of it.
        def queue_entries():
            db = sqlite3.connect(os.path.join(workdir, 'devices.db'))
            rows = db.execute('SELECT channel, event, macs FROM notification_queue'
                              ' ORDER BY id').fetchall()
            db.close()
            return [(c, e, sorted(json.loads(m))) for c, e, m in rows]

        def retry_state():
            db = sqlite3.connect(os.path.join(workdir, 'devices.db'))
            row = db.execute('SELECT attempts, next_attempt FROM notification_retry'
                             ' WHERE id = 1').fetchone()
            db.close()
            return row or (0, 0.0)

        def force_due():
            db = sqlite3.connect(os.path.join(workdir, 'devices.db'))
            db.execute('UPDATE notification_retry SET next_attempt = 0')
            db.commit()
            db.close()

        delivery['ok'] = False
        hosts[:] = [device('aa:11'), device('bb:22', 'vlan0.11'), device('cc:33')]
        check('a failed delivery is still attempted', scan(), [('new', ['cc:33'])])
        check('a failed delivery stays queued', queue_entries(), [('email', 'new', ['cc:33'])])
        check('the queue backs off 30s after one failure',
              (retry_state()[0], round(retry_state()[1] - time.time())), (1, 30))

        # Nothing is due yet, so a later event joins the queue instead of
        # burning an attempt of its own.
        hosts[:] = [device('aa:11'), device('bb:22', 'vlan0.11'),
                    device('cc:33'), device('dd:44')]
        check('a scan during the backoff does not dispatch', scan(), [])
        check('but the new event is queued',
              [(c, e) for c, e, _ in queue_entries()],
              [('email', 'new'), ('email', 'new')])
        check('and the attempt count is unchanged', retry_state()[0], 1)

        force_due()
        dispatched.clear()
        scanner.process_queue()
        check('a retry merges the backlog into one message',
              list(dispatched), [('new', ['cc:33', 'dd:44'])])
        check('a second failure still keeps one queue-wide timer',
              (retry_state()[0], round(retry_state()[1] - time.time())), (2, 30))

        delivery['ok'] = True
        force_due()
        dispatched.clear()
        scanner.process_queue()
        check('a later retry delivers everything at once',
              list(dispatched), [('new', ['cc:33', 'dd:44'])])
        check('and the queue empties', queue_entries(), [])
        check('and the backoff resets',
              (retry_state()[0], round(retry_state()[1])), (0, 0))
    finally:
        shutil.rmtree(workdir, ignore_errors=True)

    if failures:
        print('\n%d check(s) failed' % len(failures))
        return 1
    print('\nall checks passed')
    return 0


if __name__ == '__main__':
    sys.exit(main())
