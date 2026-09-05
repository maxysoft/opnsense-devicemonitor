#!/usr/local/bin/python3
"""Direct SMTP transport for OPNsense Device Monitor.

Uses only Python standard-library modules. Configuration is read from the
Device Monitor JSON config. Message data is accepted as JSON on stdin so SMTP
credentials never appear in the process command line.
"""

import json
import os
import smtplib
import socket
import ssl
import sys
from email.charset import QP, Charset
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import formataddr, getaddresses

CONFIG_FILE = "/var/db/devicemonitor/config.json"


def fail(message, code=1):
    print(json.dumps({"result": "failed", "message": str(message)}, ensure_ascii=False))
    raise SystemExit(code)


def load_config():
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as handle:
            data = json.load(handle)
    except FileNotFoundError:
        fail(f"Configuration file not found: {CONFIG_FILE}")
    except (OSError, json.JSONDecodeError) as exc:
        fail(f"Cannot read SMTP configuration: {exc}")
    return data


def parse_recipients(value):
    # Support a single address as before, while also accepting comma/semicolon
    # separated recipients for direct SMTP.
    raw = str(value or "").replace(";", ",")
    recipients = [addr.strip() for _, addr in getaddresses([raw]) if addr.strip()]
    if not recipients:
        fail("No valid recipient email address configured")
    return recipients


def main():
    config = load_config()

    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        fail(f"Invalid message payload: {exc}")

    host = str(config.get("smtp_host", "")).strip()
    try:
        port = int(config.get("smtp_port", 587))
    except (TypeError, ValueError):
        fail("Invalid SMTP port")

    encryption = str(config.get("smtp_encryption", "starttls")).strip().lower()
    username = str(config.get("smtp_username", "")).strip()
    password = str(config.get("smtp_password", ""))
    mail_from = str(config.get("email_from", "devicemonitor@opnsense.local")).strip()
    recipients = parse_recipients(config.get("email_to", ""))

    if not host:
        fail("SMTP server is not configured")
    if port < 1 or port > 65535:
        fail("SMTP port must be between 1 and 65535")
    if encryption not in {"none", "starttls", "ssl"}:
        fail("Invalid SMTP encryption mode")
    if not mail_from:
        fail("Sender email address is not configured")

    subject = str(payload.get("subject", "OPNsense Device Monitor"))
    html_body = str(payload.get("html", ""))
    text_body = str(payload.get("text", "OPNsense Device Monitor notification"))

    msg = MIMEMultipart("alternative")
    msg["Subject"] = subject
    msg["From"] = formataddr((socket.gethostname(), mail_from))
    msg["To"] = ", ".join(recipients)

    # Follow the same robust MIME approach used by arp/ndp-logging: provide
    # both plain-text and HTML alternatives and force quoted-printable UTF-8.
    qp_charset = Charset("utf-8")
    qp_charset.body_encoding = QP
    msg.attach(MIMEText(text_body, "plain", qp_charset))
    msg.attach(MIMEText(html_body, "html", qp_charset))

    context = ssl.create_default_context()
    server = None
    try:
        if encryption == "ssl":
            server = smtplib.SMTP_SSL(host, port, timeout=10, context=context)
        else:
            server = smtplib.SMTP(host, port, timeout=10)
            server.ehlo()
            if encryption == "starttls":
                server.starttls(context=context)
                server.ehlo()

        if username:
            server.login(username, password)

        server.sendmail(mail_from, recipients, msg.as_string())
        print(json.dumps({"result": "sent", "message": "Email sent", "transport": "smtp"}))
    except Exception as exc:
        fail(exc)
    finally:
        if server is not None:
            try:
                server.quit()
            except Exception:
                pass


if __name__ == "__main__":
    main()
