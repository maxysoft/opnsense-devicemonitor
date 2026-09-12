# OPNsense Device Monitor

**[🇨🇿 Česky](README_CZ.md)**

Tracks the devices on your network from OPNsense's own host discovery database
and notifies you when one appears, comes online or goes offline.

Fork of [hacesoft/opnsense-devicemonitor](https://github.com/hacesoft/opnsense-devicemonitor),
packaged as an installable OPNsense plugin.

> **AI disclaimer.** Parts of this fork, including the packaging and several
> features, were written with AI assistance and reviewed by a human before
> release. It runs on a firewall: read the code, test it where you can afford to
> break things, and report anything that looks wrong.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [How it works](#how-it-works)
- [Files and endpoints](#files-and-endpoints)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)
- [Credits](#credits)

## Features

- Device discovery from OPNsense hostwatch (`/var/db/hostwatch/hosts.db`)
- Email notifications over local sendmail or direct SMTP
- Webhook notifications: ntfy, Discord, Apprise API, or generic JSON
- Queued delivery: a notification that fails is retried until it is accepted
- Events for new devices, and for devices going offline and coming back
- Per-interface filtering, independently for the scanner, email and webhooks
- Reserved (DHCP) and new-device badges, custom names, CSV export
- Lobby dashboard widget, and service control under Diagnostics → Services

## Requirements

OPNsense 26.1.5 or newer. Earlier versions lack APIs the plugin uses.

## Installation

Add the repository once, as root over SSH:

```bash
fetch -o /usr/local/etc/pkg/repos/mxy-opnsense-repo.conf \
  https://maxysoft.github.io/opnsense-repo/mxy-opnsense-repo.conf
pkg update
```

Then install from **System → Firmware → Plugins** (search `os-devicemonitor`),
or from the shell:

```bash
pkg install os-devicemonitor
```

Upgrade with `pkg upgrade os-devicemonitor`, remove with `pkg remove
os-devicemonitor`. The database in `/var/db/devicemonitor` survives removal.

To install or roll back to a specific version, take the asset from a
[release](https://github.com/maxysoft/opnsense-repo/releases):

```bash
pkg add -f https://github.com/maxysoft/opnsense-repo/releases/download/v2.9.2/os-devicemonitor-2.9.2-FreeBSD_15_amd64.pkg
```

Use the `FreeBSD_14_amd64` asset on OPNsense 26.1. `pkg upgrade` moves it
forward again unless you `pkg lock os-devicemonitor`.

Repository source: [maxysoft/opnsense-repo](https://github.com/maxysoft/opnsense-repo)

> Installed with `install.sh` from an older release? Run `sh uninstall.sh`
> first, then install the package, or the unmanaged copies stay behind.

## Configuration

Everything is under Services → Device Monitor → Settings.

### General

| Setting | Description |
|---------|-------------|
| Enable Device Monitor | Runs the scanner daemon |
| Scan interval | Seconds between scans |
| Log level | `info`, or `debug` for per-notification detail |
| Monitor interfaces | Limit scanning to these interfaces; empty means all |
| Notify on | Which events raise a notification: new, online, offline |

### Email

Two delivery methods. **Local sendmail** uses `/usr/local/sbin/sendmail` and
needs a working local transport such as `os-postfix`. **Direct SMTP** talks to a
server itself through Python `smtplib` and needs nothing else installed.

| Setting | Description |
|---------|-------------|
| Enable email | Turns the channel on |
| To / From | Recipient and sender addresses |
| Delivery method | Local sendmail or direct SMTP |
| SMTP server, port, encryption | STARTTLS, SSL/TLS or none |
| SMTP username, password | Optional authentication |
| Email: notify for interfaces | Limit this channel to these interfaces |
| Test email | Saves the settings, then sends through the selected method |

### Webhook

| Setting | Description |
|---------|-------------|
| Enable webhook | Turns the channel on |
| Webhook type | Payload format; set it explicitly |
| Webhook URL | Target endpoint |
| Skip TLS verification | Last resort, see [TLS](#tls) |
| Webhook: notify for interfaces | Limit this channel to these interfaces |
| Test webhook | Saves the settings, then sends a test payload |

Supported types:

- **ntfy** — `https://ntfy.sh/yourSecretTopic`
- **Discord** — `https://discord.com/api/webhooks/...`
- **Apprise API** — `http://apprise.lan:8000/notify/opnsense`
- **Generic JSON** — any endpoint that accepts a POST

Detection from the URL is only a fallback and guesses wrong for a self-hosted
ntfy on a hostname that does not say so.

#### Apprise

[Apprise API](https://github.com/caronc/apprise-api) forwards one notification
to any of its services, so the targets live there rather than in this plugin:

```yaml
services:
  apprise:
    image: caronc/apprise:latest
    restart: unless-stopped
    ports:
      - "8000:8000"
    volumes:
      - ./apprise/config:/config
    environment:
      APPRISE_STATEFUL_MODE: simple
      APPRISE_ADMIN: "y"
```

Open `http://<host>:8000/`, pick a key such as `opnsense`, paste the target URLs
(`ntfys://ntfy.example.com/topic`, `discord://...`, `mailto://...`), and point
**Webhook URL** at `http://<host>:8000/notify/opnsense`. The plugin posts
`{"title", "body", "type", "format"}`, where `type` is `info` for a new device,
`success` for one coming online and `warning` for one going offline.

Apprise API has no authentication of its own, by design, and its stored
configuration holds your tokens in plaintext. Keep it on the LAN, or put basic
authentication in front of it.

#### Delivery and retries

A failed delivery is not lost. The event is queued, and the queue is retried as
a whole: 30s, 30s, 1m, 3m, 5m, 10m, 30m, then hourly for as long as it takes.
Retries continue while monitoring is switched off.

Everything queued for the same channel and event is merged into one message, so
an endpoint that was unreachable for a day produces a single notification
listing every device rather than one per scan.

The Devices page shows a **Pending notifications** panel while the queue is not
empty, with the failure reason, the time of the next attempt, and buttons to
retry now or discard. Switching a channel off discards its queue, which is
recorded in the log.

#### TLS

A certificate issued by a CA of this firewall is trusted automatically:
everything under **System → Trust → Authorities** is written to the system trust
store, and the plugin verifies against it. **Skip TLS verification** is only for
an endpoint that cannot be verified at all — it exposes the webhook URL, which
is usually itself the credential, to anyone on the path.

## Usage

### Devices page

Services → Device Monitor → Devices.

Columns are MAC, IP, hostname, vendor, interface, status and last seen. A
**NEW** badge marks a device first seen in the last 24 hours, **RESERVED** marks
a MAC with a DHCP reservation. Click a hostname to give the device your own
name. The toolbar filters by interface, status and reservation, runs a scan, and
exports the current view to CSV.

### Online status

The list follows hostwatch, which records a device whenever it is seen on the
network through ARP, NDP, DHCP or DNS. A device counts as online when it was
seen in the last 15 minutes, so nothing depends on it answering a ping.

The **Check online** button does not rely on ICMP either. It sends one probe so
the firewall resolves the address, then looks the device up in the ARP/NDP
neighbour table — the signal the DHCP leases pages use for their own online
column. A device that drops pings still has to answer ARP.

Devices behind a router do not appear in that table; only directly attached
segments do.

### Elsewhere in the GUI

- **Lobby dashboard** — add the Device Monitor widget for daemon status and device counts
- **System → Diagnostics → Services** — start, stop and restart the daemon
- `/var/log/devicemonitor.log` — rotated by newsyslog at 1 MB, 7 generations

## How it works

```
monitor_daemon.py
    ├── reads  /var/db/devicemonitor/config.json   (rendered from config.xml)
    ├── reads  /var/db/hostwatch/hosts.db          (OPNsense host discovery)
    ├── writes /var/db/devicemonitor/devices.db    (devices, notification queue)
    └── runs   scan_network.py on the scan interval, and the queue every 30s

configd
    ├── devicemonitor start|stop|restart      service control
    ├── devicemonitor status                  pidfile check
    ├── devicemonitor scan                    manual scan
    ├── devicemonitor processQueue            deliver queued notifications
    └── devicemonitor sendEmailNotification | sendWebhookNotification
```

Settings live in `config.xml` through the `General` model and are rendered into
`config.json` by a configd template; the scanner and the notification scripts
read that file.

## Files and endpoints

```
src/etc/                      rc.d script, service registration, log rotation
src/opnsense/mvc/             controllers, models, views, forms, translations
src/opnsense/scripts/         daemon, scanner, notification handlers
src/opnsense/service/         configd actions and templates
src/opnsense/www/             Lobby widget
tests/                        run without a firewall; CI runs all of them
```

Runtime:

```
/var/run/devicemonitor.pid
/var/log/devicemonitor.log
/var/db/devicemonitor/devices.db
/var/db/devicemonitor/config.json
/etc/rc.conf.d/devicemonitor
```

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/devicemonitor/devices/search` | Device list |
| GET | `/api/devicemonitor/devices/stats` | Total and online counts |
| POST | `/api/devicemonitor/devices/delete` | Delete one device |
| POST | `/api/devicemonitor/devices/clear` | Clear the database |
| POST | `/api/devicemonitor/devices/updatehostname` | Set a custom name |
| POST | `/api/devicemonitor/devices/pingdevice` | Check reachability |
| GET | `/api/devicemonitor/devices/queue` | Pending notifications |
| POST | `/api/devicemonitor/devices/retryqueue` | Retry the queue now |
| POST | `/api/devicemonitor/devices/discardqueue` | Drop the queue |
| GET | `/api/devicemonitor/settings/get` | Read settings |
| POST | `/api/devicemonitor/settings/set` | Write settings |
| POST | `/api/devicemonitor/config/testemail` | Send a test email |
| POST | `/api/devicemonitor/config/testWebhook` | Send a test webhook |
| GET | `/api/devicemonitor/config/getinterfaces` | Interface descriptions |
| GET | `/api/devicemonitor/service/status` | Daemon status |
| POST | `/api/devicemonitor/service/start\|stop\|restart` | Service control |
| POST | `/api/devicemonitor/service/scan` | Trigger a scan |
| POST | `/api/devicemonitor/service/reconfigure` | Re-render config and restart |

## Troubleshooting

Watch what the plugin is doing, with **Log level** set to `debug`:

```bash
tail -f /var/log/devicemonitor.log
grep WEBHOOK /var/log/devicemonitor.log
```

**A notification never arrives.** Check the Pending notifications panel on the
Devices page: if the event is queued, the panel shows the reason the endpoint
gave. `Test webhook` on the settings page reports the same detail directly.

**Daemon control shows "Execute error".**

```bash
sh /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/daemon_status.sh
configctl configd actions | grep devicemonitor
```

The status script must print `running` or `stopped` and never fail.

**The daemon does not start.**

```bash
tail -30 /var/log/devicemonitor.log
/usr/local/bin/python3 /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/monitor_daemon.py
```

**The widget shows dashes.** Confirm the endpoint answers, then clear the
browser cache:

```bash
curl -k -u "APIKEY:APISECRET" https://localhost/api/devicemonitor/devices/stats
```

**The database is corrupt.**

```bash
cp /var/db/devicemonitor/devices.db /var/db/devicemonitor/devices.db.backup
rm /var/db/devicemonitor/devices.db
configctl devicemonitor restart
```

## Uninstalling

```bash
pkg remove os-devicemonitor
```

Configuration in `config.xml` and the database in `/var/db/devicemonitor` are
kept. Remove them by hand if you want them gone.

## Credits

Original plugin by [Hacesoft](https://github.com/hacesoft) ([hacesoft.cz](https://hacesoft.cz)).
This fork adds the packaging, the notification queue and later features.

Version history is in the [releases](https://github.com/maxysoft/opnsense-repo/releases),
each with its changelog.

Issues: [maxysoft/opnsense-devicemonitor](https://github.com/maxysoft/opnsense-devicemonitor/issues)

## License

BSD 2-Clause — see [LICENSE](LICENSE).
