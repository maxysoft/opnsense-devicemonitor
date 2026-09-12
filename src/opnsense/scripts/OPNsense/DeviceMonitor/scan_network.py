#!/usr/local/bin/python3

import sqlite3
from datetime import datetime
import os
import json
import sys
import argparse
import subprocess
import re
import time
import xml.etree.ElementTree as ET
import fcntl

# ================================================================
# KONFIGURACE - ZAPNI/VYPNI FUNKCE
# ================================================================
DEBUG_LOGGING = True  # ← Změň na False pro vypnutí logů

# ================================================================
# CESTY - VŠECHNO NA JEDNOM MÍSTĚ!
#
#          Ukazatel na konfigurační soubor s výchozími hodnotami
#
# ================================================================
defaultsFile = '/usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/defaults.json'

def load_defaults():
    with open(defaultsFile, 'r') as f:
        return json.load(f)

# Načti na startu
_defaults = load_defaults()
PATHS = _defaults['paths']

# Cesty (místo hardcoded)
HOSTWATCH_DB = PATHS['hostwatchDb']
CONFIG_FILE = PATHS['configFile']
DB_FILE = PATHS['dbFile']
DEFAULT_CONFIG = _defaults['config']
# ================================================================


# Nastaveno z konfigurace v load_config(). "debug" zapne podrobné zprávy.
LOG_LEVEL = str(DEFAULT_CONFIG.get('log_level', 'info')).lower()


def log(message, level='INFO'):
    """Standardní append logging (rychlé!)"""
    if not DEBUG_LOGGING:
        return
    if level.upper() == 'DEBUG' and LOG_LEVEL != 'debug':
        return

    timestamp = datetime.now().strftime('%d-%m-%Y %H:%M:%S')
    prefix = 'DEBUG: ' if level.upper() == 'DEBUG' else ''
    with open("/var/log/devicemonitor.log", "a") as f:
        f.write(f"{timestamp} - {prefix}{message}\n")

def load_config():
    """Načte runtime konfiguraci"""
    
    if not os.path.exists(CONFIG_FILE):
        if DEBUG_LOGGING:
            log(f"Config file not found: {CONFIG_FILE}, using defaults")
        return {
            'enabled': DEFAULT_CONFIG['enabled'] == '1',
            'email_enabled': DEFAULT_CONFIG.get('email_enabled', '1') == '1',
            'email_to': DEFAULT_CONFIG.get('email_to', ''),
            'email_from': DEFAULT_CONFIG.get('email_from', 'devicemonitor@opnsense.local'),
            'webhook_enabled': DEFAULT_CONFIG.get('webhook_enabled', '0') == '1',
            'webhook_url': DEFAULT_CONFIG.get('webhook_url', ''),
            'scan_interval': int(DEFAULT_CONFIG.get('scan_interval', 300)),
            'notify_events': DEFAULT_CONFIG.get('notify_events', 'new'),
            'monitor_interfaces': DEFAULT_CONFIG.get('monitor_interfaces', ''),
            'email_interfaces': DEFAULT_CONFIG.get('email_interfaces', ''),
            'webhook_interfaces': DEFAULT_CONFIG.get('webhook_interfaces', ''),
            'apiEmailUrl': PATHS.get('apiEmailUrl', ''),
            'apiWebhookUrl': PATHS.get('apiWebhookUrl', '')
        }
    
    try:
        with open(CONFIG_FILE, 'r') as f:
            config = json.load(f)

            global LOG_LEVEL
            LOG_LEVEL = str(config.get('log_level', LOG_LEVEL)).lower()

            return {
                'enabled': config.get('enabled', '0') == '1',
                'email_enabled': config.get('email_enabled', '1') == '1',
                'email_to': config.get('email_to', ''),
                'email_from': config.get('email_from', 'devicemonitor@opnsense.local'),
                'webhook_enabled': config.get('webhook_enabled', '0') == '1',
                'webhook_url': config.get('webhook_url', ''),
                'scan_interval': int(config.get('scan_interval', DEFAULT_CONFIG.get('scan_interval', 300))),
                'notify_events': config.get('notify_events', DEFAULT_CONFIG.get('notify_events', 'new')),
                'monitor_interfaces': config.get('monitor_interfaces', DEFAULT_CONFIG.get('monitor_interfaces', '')),
                'email_interfaces': config.get('email_interfaces', DEFAULT_CONFIG.get('email_interfaces', '')),
                'webhook_interfaces': config.get('webhook_interfaces', DEFAULT_CONFIG.get('webhook_interfaces', '')),
                'apiEmailUrl': PATHS.get('apiEmailUrl', ''),
                'apiWebhookUrl': PATHS.get('apiWebhookUrl', '')
            }
    except Exception as e:
        if DEBUG_LOGGING:
            log(f"Config load error: {e}")
        return {
            'enabled': False,
            'email_enabled': True,
            'email_to': '',
            'email_from': 'devicemonitor@opnsense.local',
            'webhook_enabled': False,
            'webhook_url': '',
            'scan_interval': 300,
            'notify_events': 'new',
            'monitor_interfaces': '',
            'email_interfaces':  '',
            'webhook_interfaces': '',
            'apiEmailUrl': PATHS.get('apiEmailUrl'),
            'apiWebhookUrl': PATHS.get('apiWebhookUrl')
        }

    
    

def init_db():
    """Inicializace databáze"""
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    
    c.execute('''CREATE TABLE IF NOT EXISTS devices (
        mac TEXT PRIMARY KEY,
        ip TEXT,
        hostname TEXT,
        vendor TEXT,
        vlan TEXT,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        notified INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 0,
        notification_pending INTEGER DEFAULT 0
    )''')
    
    # Přidej sloupce pokud neexistují (pro zpětnou kompatibilitu)   
    try:
        c.execute('ALTER TABLE devices ADD COLUMN first_seen DATETIME DEFAULT CURRENT_TIMESTAMP')
    except:
        pass

    try:
        c.execute('ALTER TABLE devices ADD COLUMN custom_hostname TEXT DEFAULT NULL')
    except:
        pass

    try:
        c.execute('ALTER TABLE devices ADD COLUMN is_reserved INTEGER DEFAULT 0')
    except:
        pass

    try:
        c.execute("ALTER TABLE devices ADD COLUMN pending_event TEXT DEFAULT 'new'")
    except:
        pass

    # Only full_scan() writes this. is_active is also written by the GUI
    # ("Check online"), which would otherwise look like an up/down transition.
    try:
        c.execute('ALTER TABLE devices ADD COLUMN scan_active INTEGER DEFAULT 0')
    except:
        pass

    # A failed delivery must not lose the event, so notifications are queued
    # and retried rather than sent once and forgotten.
    c.execute('''CREATE TABLE IF NOT EXISTS notification_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL,
        event TEXT NOT NULL,
        macs TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )''')

    # The retry schedule is a property of the queue, not of one entry, so it
    # lives in a single row rather than being repeated per notification.
    c.execute('''CREATE TABLE IF NOT EXISTS notification_retry (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        attempts INTEGER DEFAULT 0,
        next_attempt REAL DEFAULT 0,
        last_error TEXT
    )''')

    # Tombstones for manually deleted devices. Historical Hostwatch records
    # must not immediately recreate a device that the user removed.
    c.execute('''CREATE TABLE IF NOT EXISTS deleted_devices (
        mac TEXT PRIMARY KEY,
        last_seen DATETIME,
        deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )''')
    
    conn.commit()
    conn.close()


def get_hostwatch_devices():
    """Načte zařízení přímo z OPNsense hostwatch databáze"""
    devices = []
    
    if not os.path.exists(HOSTWATCH_DB):
        log("CHYBA: hostwatch databáze neexistuje: " + HOSTWATCH_DB)
        return devices
    
    try:
        conn = sqlite3.connect(f'file:{HOSTWATCH_DB}?mode=ro', uri=True)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()
        
        cursor.execute('''
            SELECT 
                interface_name,
                ip_address,
                ether_address,
                first_seen,
                last_seen,
                organization_name
            FROM v_hosts
            WHERE protocol = 'inet'
              AND ether_address NOT IN ('ff:ff:ff:ff:ff:ff', '00:00:00:00:00:00')
              AND ip_address NOT LIKE '169.254.%'
            ORDER BY last_seen DESC
        ''')
        
        rows = cursor.fetchall()
        conn.close()
        
        # The query is sorted newest-first. Keep only the first row for each
        # MAC so historical Hostwatch records cannot overwrite current data.
        seen_macs = set()
        skipped_duplicates = 0

        for row in rows:
            mac = (row['ether_address'] or '').lower().strip()
            if not mac:
                continue
            if mac in seen_macs:
                skipped_duplicates += 1
                continue
            seen_macs.add(mac)

            # Mapuj interface_name na VLAN popis
            iface = row['interface_name'] or ''
            vlan = map_interface_to_vlan(iface)
            
            vendor = row['organization_name'] or 'Unknown'
            if len(vendor) > 40:
                vendor = vendor[:37] + '...'
            
            devices.append({
                'mac': mac,
                'ip': row['ip_address'] or '',
                'hostname': '',
                'vendor': vendor,
                'vlan': vlan,
                'first_seen': row['first_seen'] or '',
                'last_seen': row['last_seen'] or '',
            })
        
        log(
            f"Hostwatch DB: načteno {len(rows)} záznamů, "
            f"{len(devices)} unikátních MAC, přeskočeno {skipped_duplicates} historických duplicit"
        )
        
    except Exception as e:
        log(f"Chyba čtení hostwatch DB: {e}")
    
    return devices


_INTERFACE_MAP = None


def get_interface_map():
    """Fyzické zařízení (vlan0.11, igc0) -> klíč rozhraní v OPNsense (lan, opt2).

    Ukládá se klíč, ne vygenerovaný popisek, aby se dal ve filtrech použít
    InterfaceField: ten nabízí přesně tyto klíče.
    """
    global _INTERFACE_MAP
    if _INTERFACE_MAP is not None:
        return _INTERFACE_MAP

    mapping = {}
    try:
        root = ET.parse('/conf/config.xml').getroot()
        interfaces = root.find('interfaces')
        if interfaces is not None:
            for iface in interfaces:
                if_el = iface.find('if')
                if if_el is not None and if_el.text:
                    mapping[if_el.text.strip()] = iface.tag
        log(f"Rozhraní: {len(mapping)} přiřazení")
    except Exception as e:
        log(f"Chyba čtení rozhraní z config.xml: {e}")

    _INTERFACE_MAP = mapping
    return mapping


def map_interface_to_vlan(interface_name):
    """Fyzické zařízení -> klíč rozhraní, s fallbackem na starý popisek.

    Nepřiřazené rozhraní žádný klíč nemá, takže zařízení na něm si nechá
    původní podobu (VLAN11 / IGC0) a nezmizí ze seznamu.
    """
    assigned = get_interface_map().get((interface_name or '').strip())
    if assigned:
        return assigned
    if not interface_name:
        return 'Unknown'
    
    # vlan0.11 → VLAN11
    match = re.search(r'vlan\d+\.(\d+)', interface_name, re.I)
    if match:
        return 'VLAN' + match.group(1)
    
    # igc0, igc1 → interface název
    return interface_name.upper()


def get_dhcp_descriptions():
    """Načte popisky zařízení z DHCP statických přiřazení (/conf/config.xml)"""
    descriptions = {}
    try:
        tree = ET.parse('/conf/config.xml')
        root = tree.getroot()
        dhcpd = root.find('dhcpd')
        if dhcpd is None:
            return descriptions

        for iface in dhcpd:
            # Přeskočit vypnuté ISC DHCP interfacy
            enable_el = iface.find('enable')
            if enable_el is None:
                continue
            for staticmap in iface.findall('staticmap'):
                mac_el = staticmap.find('mac')
                hostname_el = staticmap.find('hostname')
                descr_el = staticmap.find('descr')
                if mac_el is not None and mac_el.text:
                    mac = mac_el.text.lower().strip()
                    # Preferuj hostname, fallback na descr
                    if hostname_el is not None and hostname_el.text:
                        descriptions[mac] = hostname_el.text.strip()
                    elif descr_el is not None and descr_el.text:
                        descriptions[mac] = descr_el.text.strip()

        log(f"DHCP popisky: {len(descriptions)} záznamů")
    except Exception as e:
        log(f"Chyba čtení config.xml: {e}")
    return descriptions

def get_dnsmasq_descriptions():
    """Načte popisky zařízení z Dnsmasq Host Overrides (/conf/config.xml)"""
    descriptions = {}
    try:
        tree = ET.parse('/conf/config.xml')
        root = tree.getroot()
        dnsmasq = root.find('dnsmasq')
        if dnsmasq is None:
            return descriptions

        for host in dnsmasq.findall('hosts'):
            hw_el = host.find('hwaddr')
            host_el = host.find('host')
            descr_el = host.find('descr')
            if hw_el is not None and hw_el.text:
                mac = hw_el.text.lower().strip()
                # Preferuj host (hostname), fallback na descr
                if host_el is not None and host_el.text:
                    descriptions[mac] = host_el.text.strip()
                elif descr_el is not None and descr_el.text:
                    descriptions[mac] = descr_el.text.strip()

        log(f"Dnsmasq popisky: {len(descriptions)} záznamů")
    except Exception as e:
        log(f"Chyba čtení Dnsmasq config.xml: {e}")
    return descriptions

def get_reserved_macs():
    """MAC adresy, které mají DHCP rezervaci (ISC staticmap nebo Dnsmasq host).

    Záměrně se nepoužívají mapy popisků: ty přeskakují rezervace bez hostname
    i descr, které jsou ale pořád rezervace. Firewall vidí jen rezervace, ne
    adresu nastavenou ručně na samotném zařízení.
    """
    reserved = set()
    try:
        root = ET.parse('/conf/config.xml').getroot()

        dhcpd = root.find('dhcpd')
        if dhcpd is not None:
            for iface in dhcpd:
                if iface.find('enable') is None:
                    continue
                for staticmap in iface.findall('staticmap'):
                    mac_el = staticmap.find('mac')
                    if mac_el is not None and mac_el.text:
                        reserved.add(mac_el.text.lower().strip())

        dnsmasq = root.find('dnsmasq')
        if dnsmasq is not None:
            for host in dnsmasq.findall('hosts'):
                hw_el = host.find('hwaddr')
                if hw_el is not None and hw_el.text:
                    reserved.add(hw_el.text.lower().strip())

        # Kea je na 26.x plnohodnotný DHCP server, rezervace jsou jinde.
        for path in ('OPNsense/Kea/dhcp4/reservations/reservation',
                     'OPNsense/Kea/dhcp6/reservations/reservation'):
            for res in root.findall(path):
                hw_el = res.find('hw_address')
                if hw_el is not None and hw_el.text:
                    reserved.add(hw_el.text.lower().strip())

        log(f"DHCP rezervace: {len(reserved)} MAC adres")
    except Exception as e:
        log(f"Chyba čtení rezervací z config.xml: {e}")
    return reserved


def is_recently_seen(last_seen_str, minutes=15):
    """True pokud bylo zařízení viděno v posledních N minutách (porovnání v UTC)"""
    if not last_seen_str:
        return False
    try:
        last_seen = datetime.strptime(last_seen_str, '%Y-%m-%d %H:%M:%S')
        now_utc = datetime.utcnow()
        return (now_utc - last_seen).total_seconds() < minutes * 60
    except:
        return False
    

# Notifications survive a failed delivery. The retry delay belongs to the
# queue as a whole rather than to a single entry: one failure holds everything
# back, and the next attempt then flushes all of it in one go.
RETRY_BACKOFF = [30, 30, 60, 180, 300, 600, 1800, 3600]

# An endpoint that stays unreachable for days must not grow the database
# without bound. The oldest entries are the least worth keeping.
QUEUE_LIMIT = 100

CONFIGD_ACTIONS = {
    'email': 'sendEmailNotification',
    'webhook': 'sendWebhookNotification',
}


def read_retry_state():
    """(attempts, next_attempt) for the queue as a whole."""
    try:
        with sqlite3.connect(DB_FILE) as conn:
            row = conn.execute(
                'SELECT attempts, next_attempt FROM notification_retry WHERE id = 1'
            ).fetchone()
    except Exception:
        return 0, 0.0
    if not row:
        return 0, 0.0
    return int(row[0] or 0), float(row[1] or 0.0)


def write_retry_state(attempts, next_attempt, last_error=''):
    try:
        with sqlite3.connect(DB_FILE) as conn:
            conn.execute(
                'INSERT INTO notification_retry (id, attempts, next_attempt, last_error)'
                ' VALUES (1, ?, ?, ?)'
                ' ON CONFLICT(id) DO UPDATE SET attempts = excluded.attempts,'
                ' next_attempt = excluded.next_attempt, last_error = excluded.last_error',
                (attempts, next_attempt, last_error)
            )
    except Exception as e:
        log(f"[QUEUE] cannot record the retry state: {e}")


def enqueue_notification(channel, event, devices):
    """Record an event for delivery. process_queue() does the sending."""
    macs = [d['mac'] for d in devices if d.get('mac')]
    if not macs:
        return
    try:
        with sqlite3.connect(DB_FILE) as conn:
            conn.execute(
                'INSERT INTO notification_queue (channel, event, macs) VALUES (?, ?, ?)',
                (channel, event, json.dumps(macs))
            )
            conn.execute(
                'DELETE FROM notification_queue WHERE id NOT IN '
                '(SELECT id FROM notification_queue ORDER BY id DESC LIMIT ?)',
                (QUEUE_LIMIT,)
            )
    except Exception as e:
        log(f"[QUEUE] cannot queue {channel}/{event}: {e}")


def dispatch_queued(channel, event, macs):
    """Send one batch. Returns (done, message).

    done=True means the batch can be dropped: either it was delivered, or
    there is nothing left to report. Only a real delivery failure is retried.
    """
    action = CONFIGD_ACTIONS.get(channel)
    if action is None:
        return True, f"unknown channel {channel}"

    try:
        with sqlite3.connect(DB_FILE) as conn:
            # The pending flag has to describe exactly this batch, because the
            # PHP side reads the whole flagged set and sends it as one message.
            conn.execute('UPDATE devices SET notification_pending = 0')
            conn.executemany(
                'UPDATE devices SET notification_pending = 1, pending_event = ? WHERE mac = ?',
                [(event, mac) for mac in macs]
            )
            marked = conn.execute(
                'SELECT COUNT(*) FROM devices WHERE notification_pending = 1'
            ).fetchone()[0]

        if not marked:
            # Every device in the batch has since been deleted.
            return True, 'no devices left to report'

        result = subprocess.run(
            ['/usr/local/sbin/configctl', 'devicemonitor', action],
            capture_output=True, text=True, timeout=60
        )
    except Exception as e:
        return False, str(e)[:200]

    # The notify scripts print a JSON verdict, which configd can wrap.
    out = (result.stdout or '').strip()
    start, end = out.find('{'), out.rfind('}')
    verdict = {}
    if start != -1 and end > start:
        try:
            verdict = json.loads(out[start:end + 1])
        except ValueError:
            verdict = {}

    status = str(verdict.get('result', ''))
    if status in ('sent', 'ok'):
        return True, 'delivered'
    if status == 'skipped':
        # The channel is switched off or has nothing to report; retrying
        # cannot help, so the batch is dropped. Logged, because for a user who
        # disabled the channel to stop the noise this discards the backlog.
        message = str(verdict.get('message', 'skipped'))[:200]
        log(f"[QUEUE] {channel}/{event} dropped without sending: {message}")
        return True, message
    if not verdict:
        return False, ((result.stderr or out) or 'configd returned no verdict').strip()[:200]
    return False, str(verdict.get('message', 'delivery failed'))[:200]


def process_queue():
    """Deliver the whole queue when it is due.

    Entries for the same channel and event are merged into one message: the
    PHP senders report the flagged set as a single notification, so a backlog
    that built up over hours arrives as one message per channel and event
    instead of dozens.
    """
    init_db()

    attempts, next_attempt = read_retry_state()
    now = time.time()
    if next_attempt > now:
        return 0

    try:
        with sqlite3.connect(DB_FILE) as conn:
            entries = conn.execute(
                'SELECT id, channel, event, macs FROM notification_queue ORDER BY id'
            ).fetchall()
    except Exception as e:
        log(f"[QUEUE] cannot read the queue: {e}")
        return 1

    if not entries:
        if attempts or next_attempt:
            write_retry_state(0, 0, '')
        return 0

    batches = {}
    order = []
    for entry_id, channel, event, macs_json in entries:
        try:
            macs = json.loads(macs_json)
        except ValueError:
            macs = []
        key = (channel, event)
        if key not in batches:
            batches[key] = {'ids': [], 'macs': []}
            order.append(key)
        batches[key]['ids'].append(entry_id)
        for mac in macs:
            if mac not in batches[key]['macs']:
                batches[key]['macs'].append(mac)

    delivered = []
    failed = 0
    last_error = ''
    for channel, event in order:
        batch = batches[(channel, event)]
        done, message = dispatch_queued(channel, event, batch['macs'])
        if done:
            delivered.extend(batch['ids'])
            log(f"[QUEUE] {channel}/{event}: {message}", 'DEBUG')
        else:
            failed += 1
            last_error = message
            log(f"[QUEUE] {channel}/{event} failed: {message}")

    try:
        with sqlite3.connect(DB_FILE) as conn:
            if delivered:
                conn.execute(
                    'DELETE FROM notification_queue WHERE id IN (%s)'
                    % ','.join('?' * len(delivered)),
                    delivered
                )
            # Never leave stale flags behind for the next run to pick up.
            conn.execute('UPDATE devices SET notification_pending = 0')
    except Exception as e:
        log(f"[QUEUE] cannot update the queue: {e}")

    if failed:
        attempts += 1
        delay = RETRY_BACKOFF[min(attempts - 1, len(RETRY_BACKOFF) - 1)]
        write_retry_state(attempts, time.time() + delay, last_error)
        log(f"[QUEUE] {failed} notification(s) undelivered; "
            f"attempt {attempts}, retrying in {delay}s")
    else:
        write_retry_state(0, 0, '')

    return 0


# ================================================================
# HLAVNÍ FUNKCE - REFAKTOROVANÉ
# ================================================================

def update_status_only():
    """Rychlá aktualizace online/offline statusu z hostwatch DB"""
    log("Quick status update (hostwatch DB)")
    init_db()

    devices = get_hostwatch_devices()
    if not devices:
        log("Žádná data z hostwatch DB")
        print("ERROR: No hostwatch data")
        return 1

    conn = sqlite3.connect(DB_FILE)
    cursor = conn.cursor()
    cursor.execute("UPDATE devices SET is_active = 0")
    for d in devices:
        if is_recently_seen(d.get('last_seen', '')):
            cursor.execute(
                "UPDATE devices SET is_active = 1, last_seen = ? WHERE mac = ?",
                (d.get('last_seen', ''), d['mac'])
            )

    conn.commit()
    online = conn.execute("SELECT COUNT(*) FROM devices WHERE is_active = 1").fetchone()[0]
    total = conn.execute("SELECT COUNT(*) FROM devices").fetchone()[0]
    conn.close()

    log(f"Status: {online}/{total} online")
    print(f"OK: {online}/{total} online")
    return 0


def full_scan():
    """Kompletní scan z OPNsense hostwatch DB + DHCP popisky"""
    log("Spouštím full scan (hostwatch DB)...")
    config = load_config()
    init_db()

    # 1. Data z hostwatch
    devices = get_hostwatch_devices()
    if not devices:
        log("CHYBA: Žádná data z hostwatch DB")
        return 1

    # 2. DHCP popisky (ISC + Dnsmasq, Dnsmasq má přednost)
    dhcp_descriptions = get_dhcp_descriptions()
    dnsmasq_descriptions = get_dnsmasq_descriptions()
    dhcp_descriptions.update(dnsmasq_descriptions)  # Dnsmasq přepíše ISC pokud existuje stejné MAC
    reserved_macs = get_reserved_macs()

    # 3. Aktualizace vlastní DB
    new_devices = []
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    conn = sqlite3.connect(DB_FILE)

    # Stav před skenem, aby šlo poznat přechody online/offline. Musí se načíst
    # dřív, než se is_active vynuluje.
    prior_active = {
        row[0]: (row[1] or 0)
        for row in conn.execute('SELECT mac, scan_active FROM devices')
    }

    conn.execute('UPDATE devices SET is_active = 0, scan_active = 0, notification_pending = 0')

    # Prázdný výběr = sleduj všechna přiřazená rozhraní.
    monitor_ifs = set(
        v.strip() for v in str(config.get('monitor_interfaces', '')).split(',') if v.strip()
    )

    for device in devices:
        mac = device['mac']
        if not mac:
            continue

        # Zařízení z nesledovaného rozhraní se vůbec nezaznamenává.
        if monitor_ifs and device.get('vlan', '') not in monitor_ifs:
            continue

        # Obohacení o DHCP popis
        if mac in dhcp_descriptions:
            device['hostname'] = dhcp_descriptions[mac]

        is_active = 1 if is_recently_seen(device.get('last_seen', '')) else 0
        last_seen = device.get('last_seen') or now
        first_seen = device.get('first_seen') or now

        # If the user manually deleted this device, ignore the same historical
        # Hostwatch record. A genuinely newer last_seen means the device has
        # returned to the network, so remove the tombstone and add it again.
        deleted_row = conn.execute(
            'SELECT last_seen FROM deleted_devices WHERE mac = ?', (mac,)
        ).fetchone()
        if deleted_row:
            deleted_last_seen = deleted_row[0] or ''
            if deleted_last_seen and last_seen <= deleted_last_seen:
                continue
            conn.execute('DELETE FROM deleted_devices WHERE mac = ?', (mac,))

        row = conn.execute(
            'SELECT mac, custom_hostname FROM devices WHERE mac = ?', (mac,)
        ).fetchone()

        is_reserved = 1 if mac in reserved_macs else 0
        device['is_reserved'] = is_reserved

        if row:
            hostname = row[1] if row[1] else device['hostname']
            conn.execute('''
                UPDATE devices
                SET ip = ?, hostname = ?, vendor = ?, vlan = ?,
                    last_seen = ?, is_active = ?, scan_active = ?, is_reserved = ?
                WHERE mac = ?
            ''', (device['ip'], hostname, device['vendor'],
                  device['vlan'], last_seen, is_active, is_active, is_reserved, mac))
        else:
            conn.execute('''
                INSERT INTO devices
                    (mac, ip, hostname, vendor, vlan, first_seen, last_seen,
                     is_active, scan_active, notification_pending, is_reserved)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
            ''', (mac, device['ip'], device['hostname'], device['vendor'],
                  device['vlan'], first_seen, last_seen, is_active, is_active, is_reserved))
            device['first_seen'] = first_seen
            new_devices.append(device)

    conn.commit()
    online = conn.execute(
        "SELECT COUNT(*) FROM devices WHERE is_active = 1"
    ).fetchone()[0]
    total = conn.execute("SELECT COUNT(*) FROM devices").fetchone()[0]

    # Snímek pro detekci přechodů. Musí se pořídit před zavřením spojení.
    current_rows = conn.execute(
        'SELECT mac, ip, hostname, vendor, vlan, first_seen, last_seen, scan_active FROM devices'
    ).fetchall()
    conn.close()

    # 4. Notifikace (podle zvolených událostí a filtrování podle VLAN)
    log(f"Nová zařízení: {len(new_devices)}, Online: {online}/{total}")
    if config['enabled']:
        wanted = set(
            e.strip() for e in str(config.get('notify_events', 'new')).split(',') if e.strip()
        )

        new_macs = {d['mac'] for d in new_devices}
        events = {'new': new_devices, 'up': [], 'down': []}

        # Přechody se počítají jen pro zařízení, která už v DB byla. Nové
        # zařízení je "new", ne "up", jinak by se hlásilo dvakrát.
        for row in current_rows:
            mac, is_now = row[0], (row[7] or 0)
            if mac in new_macs or mac not in prior_active:
                continue
            # Nesledované rozhraní není výpadek. Řídí se podle rozhraní na
            # řádku, ne podle toho, co tento sken přeskočil: zařízení, které
            # mezitím vypadlo z hostwatch, by se jinak ohlásilo jako offline.
            if monitor_ifs and (row[4] or '') not in monitor_ifs:
                continue
            was = prior_active[mac]
            if was == 0 and is_now == 1:
                bucket = 'up'
            elif was == 1 and is_now == 0:
                bucket = 'down'
            else:
                continue
            events[bucket].append({
                'mac': mac, 'ip': row[1], 'hostname': row[2], 'vendor': row[3],
                'vlan': row[4], 'first_seen': row[5], 'last_seen': row[6],
            })

        email_ifs   = set(v.strip() for v in config.get('email_interfaces',  '').split(',') if v.strip())
        webhook_ifs = set(v.strip() for v in config.get('webhook_interfaces', '').split(',') if v.strip())

        for event in ('new', 'up', 'down'):
            devs = events[event]
            if not devs or event not in wanted:
                continue
            log(f"Událost '{event}': {len(devs)} zařízení")

            email_devs = [d for d in devs if not email_ifs or d.get('vlan', '') in email_ifs]
            webhook_devs = [d for d in devs if not webhook_ifs or d.get('vlan', '') in webhook_ifs]

            if email_devs and config.get('email_enabled') and config.get('email_to'):
                enqueue_notification('email', event, email_devs)
            if webhook_devs and config.get('webhook_enabled') and config.get('webhook_url'):
                enqueue_notification('webhook', event, webhook_devs)

    # Deliver what is due, including anything an earlier scan could not get
    # through. process_queue() clears the pending flags when it is finished.
    process_queue()

    return 0


def acquire_scan_lock():
    """Jediný sken v jednu chvíli.

    Tlačítko v GUI a daemon spouštějí tentýž skript a sdílejí sloupec
    notification_pending. Paralelní sken příznak druhému smaže mezi zápisem
    a čtením v PHP, takže notifikace zmizí, nebo se pošle pod špatnou událostí.
    """
    try:
        handle = open('/var/run/devicemonitor.scan.lock', 'w')
    except OSError:
        return None
    try:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        handle.close()
        return None
    return handle


def main():
    """Hlavní entry point s parsováním argumentů"""
    
    # Parsuj argumenty
    parser = argparse.ArgumentParser(
        description='OPNsense Device Monitor - Network Scanner',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog='''
Examples:
  %(prog)s                    # Full scan (default)
  %(prog)s --update-only      # Quick status update (hostwatch DB)
  %(prog)s --verbose          # Full scan with verbose output
  %(prog)s --help             # Show this help
        '''
    )
    
    parser.add_argument(
        '--update-only',
        action='store_true',
        help='Quick mode: only update online/offline status via hostwatch DB'
    )
    
    parser.add_argument(
        '--process-queue',
        action='store_true',
        help='Only deliver queued notifications that are due, then exit'
    )

    parser.add_argument(
        '--verbose', '-v',
        action='store_true',
        help='Enable verbose output'
    )
    
    args = parser.parse_args()
    
    # Verbose mode
    global DEBUG_LOGGING, LOG_LEVEL
    if args.verbose:
        DEBUG_LOGGING = True
        LOG_LEVEL = 'debug'

    scan_lock = acquire_scan_lock()
    if scan_lock is None:
        log("Jiný sken už běží, tento se přeskakuje")
        return 0
    
    try:
        # Rozhodnutí podle režimu
        if args.process_queue:
            return process_queue()
        if args.update_only:
            return update_status_only()
        else:
            return full_scan()
            
    except KeyboardInterrupt:
        log("Scan interrupted by user")
        print("\nInterrupted")
        return 1
    except Exception as e:
        log(f"Fatal error: {e}")
        print(f"ERROR: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        return 1


if __name__ == '__main__':
    sys.exit(main())