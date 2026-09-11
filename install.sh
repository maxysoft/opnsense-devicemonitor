#!/bin/sh

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Device Monitor - Instalace"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# ============================================
# [1/9] KONTROLY
# ============================================

echo "[1/9] Provádím kontroly..."

[ "$(id -u)" != "0" ] && {
    echo "  ✗ CHYBA: Musíš být root!"
    exit 1
}
echo "  ✓ Root oprávnění OK"

# Verze je jediná v packaging Makefile a v git tagu, tady se jen zobrazuje.
PLUGIN_VER=$(git describe --tags --always 2>/dev/null || echo "ze zdrojáků")
echo "  ✓ Verze pluginu: Device Monitor v${PLUGIN_VER}"

OPNSENSE_VER=$(opnsense-version 2>/dev/null | awk '{print $2}')
if [ -n "$OPNSENSE_VER" ]; then
    OPNSENSE_MAJOR=$(echo "$OPNSENSE_VER" | cut -d. -f1)
    OPNSENSE_MINOR=$(echo "$OPNSENSE_VER" | cut -d. -f2)
    OPNSENSE_PATCH=$(echo "$OPNSENSE_VER" | cut -d. -f3)
    if [ "$OPNSENSE_MAJOR" -lt 26 ] || \
       ( [ "$OPNSENSE_MAJOR" -eq 26 ] && [ "$OPNSENSE_MINOR" -lt 1 ] ) || \
       ( [ "$OPNSENSE_MAJOR" -eq 26 ] && [ "$OPNSENSE_MINOR" -eq 1 ] && [ "${OPNSENSE_PATCH:-0}" -lt 5 ] ); then
        echo "  ✗ CHYBA: Vyžadována OPNsense >= 26.1.5, nalezena $OPNSENSE_VER"
        exit 1
    fi
    echo "  ✓ OPNsense $OPNSENSE_VER OK (minimum 26.1.5)"
else
    echo "  ⚠ Verzi OPNsense nelze zjistit, pokračuji..."
fi

SCRIPT_DIR=$(dirname "$(readlink -f "$0")")
cd "$SCRIPT_DIR" || exit 1

[ ! -d "src" ] && {
    echo "  ✗ CHYBA: src/ adresář nenalezen!"
    exit 1
}
echo "  ✓ Zdrojové soubory nalezeny"

if ! command -v msgfmt >/dev/null 2>&1; then
    echo "  → msgfmt nenalezen, instaluji gettext-tools..."
    pkg install -y gettext-tools
    [ $? -eq 0 ] && echo "  ✓ gettext-tools nainstalován" \
                 || echo "  ⚠ gettext-tools se nepodařilo nainstalovat - překlady nebudou fungovat"
else
    echo "  ✓ msgfmt dostupný"
fi

# ============================================
# [2/9] ODINSTALACE STARÉ VERZE
# ============================================

if [ -f "/etc/rc.d/devicemonitor" ] || \
   [ -d "/usr/local/opnsense/mvc/app/controllers/OPNsense/DeviceMonitor" ]; then
    echo ""
    echo "[2/9] Detekována stará instalace, provádím aktualizaci..."
    if [ -f "$SCRIPT_DIR/uninstall.sh" ]; then
        sh "$SCRIPT_DIR/uninstall.sh" --silent
        echo "  ✓ Stará verze odstraněna (data zachována)"
    else
        echo "  ⚠ uninstall.sh nenalezen, pokračuji s přepisem..."
    fi
    sleep 1
else
    echo ""
    echo "[2/9] Nová instalace detekována"
fi

# ============================================
# [3/9] ADRESÁŘE
# ============================================

echo ""
echo "[3/9] Vytvářím adresářovou strukturu..."

mkdir -p /usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/Menu
mkdir -p /usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/ACL
mkdir -p /usr/local/opnsense/mvc/app/controllers/OPNsense/DeviceMonitor/Api
mkdir -p /usr/local/opnsense/mvc/app/views/OPNsense/DeviceMonitor
mkdir -p /usr/local/opnsense/scripts/OPNsense/DeviceMonitor
mkdir -p /usr/local/opnsense/service/conf/actions.d
mkdir -p /usr/local/opnsense/service/templates/OPNsense/DeviceMonitor
mkdir -p /usr/local/opnsense/www/js/widgets/Metadata
mkdir -p /usr/local/etc/inc/plugins.inc.d
mkdir -p /usr/local/etc/rc.d
mkdir -p /usr/local/etc/newsyslog.conf.d
mkdir -p /etc/rc.d
mkdir -p /var/db/devicemonitor
chmod 755 /var/db/devicemonitor

echo "  ✓ Adresáře vytvořeny"

# ============================================
# [4/9] RC SCRIPT + REGISTRACE SLUŽBY
# ============================================

echo ""
echo "[4/9] Instaluji RC script a registraci služby..."

if [ -f "src/etc/rc.d/devicemonitor" ]; then
    cp src/etc/rc.d/devicemonitor /usr/local/etc/rc.d/devicemonitor
    chmod +x /usr/local/etc/rc.d/devicemonitor
    ln -sf /usr/local/etc/rc.d/devicemonitor /etc/rc.d/devicemonitor
    echo "  ✓ RC script nainstalován (/usr/local/etc/rc.d/)"
else
    echo "  ✗ VAROVÁNÍ: RC script nenalezen!"
fi

# Registrace v Diagnostics → Services (plugins_services() mechanismus)
if [ -f "src/etc/inc/plugins.inc.d/devicemonitor.inc" ]; then
    cp src/etc/inc/plugins.inc.d/devicemonitor.inc \
       /usr/local/etc/inc/plugins.inc.d/
    chmod 644 /usr/local/etc/inc/plugins.inc.d/devicemonitor.inc
    echo "  ✓ Registrace služby v Diagnostics → Services"
else
    echo "  ✗ VAROVÁNÍ: plugins.inc.d/devicemonitor.inc nenalezen!"
fi

# Rotace logu, jinak /var/log/devicemonitor.log roste bez omezení
if [ -f "src/etc/newsyslog.conf.d/devicemonitor.conf" ]; then
    cp src/etc/newsyslog.conf.d/devicemonitor.conf /usr/local/etc/newsyslog.conf.d/
    chmod 644 /usr/local/etc/newsyslog.conf.d/devicemonitor.conf
    echo "  ✓ Rotace logu nastavena"
else
    echo "  ✗ VAROVÁNÍ: newsyslog.conf.d/devicemonitor.conf nenalezen!"
fi

# ============================================
# [5/9] WIDGET
# ============================================

echo ""
echo "[5/9] Instaluji widget..."

if [ -f "src/opnsense/www/js/widgets/DeviceMonitor.js" ]; then
    cp src/opnsense/www/js/widgets/DeviceMonitor.js \
       /usr/local/opnsense/www/js/widgets/
    chmod 644 /usr/local/opnsense/www/js/widgets/DeviceMonitor.js
    echo "  ✓ Widget JS nainstalován"
else
    echo "  ✗ VAROVÁNÍ: DeviceMonitor.js nenalezen!"
fi

if [ -f "src/opnsense/www/js/widgets/Metadata/DeviceMonitor.xml" ]; then
    cp src/opnsense/www/js/widgets/Metadata/DeviceMonitor.xml \
       /usr/local/opnsense/www/js/widgets/Metadata/
    chmod 644 /usr/local/opnsense/www/js/widgets/Metadata/DeviceMonitor.xml
    echo "  ✓ Widget metadata nainstalována"
else
    echo "  ✗ VAROVÁNÍ: DeviceMonitor.xml nenalezen!"
fi

# ============================================
# [6/9] PŘEKLADY
# ============================================

echo ""
echo "[6/9] Instaluji překlady..."

if [ -f "src/opnsense/mvc/app/languages/cs_CZ_devicemonitor.po" ]; then
    if command -v msgfmt >/dev/null 2>&1; then
        msgfmt -o /usr/local/opnsense/mvc/app/languages/cs_CZ_devicemonitor.mo \
                  src/opnsense/mvc/app/languages/cs_CZ_devicemonitor.po \
            && echo "  ✓ Překlad cs_CZ zkompilován" \
            || echo "  ✗ CHYBA: Kompilace cs_CZ selhala"
        msgfmt -o /usr/local/opnsense/mvc/app/languages/en_US_devicemonitor.mo \
                  src/opnsense/mvc/app/languages/en_US_devicemonitor.po \
            && echo "  ✓ Překlad en_US zkompilován" \
            || echo "  ✗ CHYBA: Kompilace en_US selhala"
    else
        echo "  ⚠ msgfmt není dostupný - překlady nebudou fungovat"
    fi
else
    echo "  ⚠ Překladové soubory nenalezeny"
fi

# ============================================
# [7/9] MVC (Models, Controllers, Views)
# ============================================

echo ""
echo "[7/9] Kopíruji MVC soubory..."

cp src/opnsense/mvc/app/models/OPNsense/DeviceMonitor/DeviceMonitor.php \
   /usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/
cp src/opnsense/mvc/app/models/OPNsense/DeviceMonitor/Menu/Menu.xml \
   /usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/Menu/
cp src/opnsense/mvc/app/models/OPNsense/DeviceMonitor/ACL/ACL.xml \
   /usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/ACL/
cp src/opnsense/mvc/app/models/OPNsense/DeviceMonitor/defaults.json \
   /usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/
echo "  ✓ Models nainstalovány"

cp src/opnsense/mvc/app/controllers/OPNsense/DeviceMonitor/IndexController.php \
   /usr/local/opnsense/mvc/app/controllers/OPNsense/DeviceMonitor/
cp src/opnsense/mvc/app/controllers/OPNsense/DeviceMonitor/Api/*.php \
   /usr/local/opnsense/mvc/app/controllers/OPNsense/DeviceMonitor/Api/
echo "  ✓ Controllers nainstalovány"

cp src/opnsense/mvc/app/views/OPNsense/DeviceMonitor/*.volt \
   /usr/local/opnsense/mvc/app/views/OPNsense/DeviceMonitor/
echo "  ✓ Views nainstalovány"

# ============================================
# [8/9] SKRIPTY A KONFIGURACE
# ============================================

echo ""
echo "[8/9] Instaluji skripty a konfiguraci..."

cp src/opnsense/scripts/OPNsense/DeviceMonitor/*.py \
   /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/
chmod +x /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/*.py
echo "  ✓ Python skripty nainstalovány"

cp src/opnsense/scripts/OPNsense/DeviceMonitor/*.sh \
   /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/
chmod +x /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/*.sh
echo "  ✓ Shell skripty nainstalovány"

cp src/opnsense/scripts/OPNsense/DeviceMonitor/*.php \
   /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/
chmod +x /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/*.php
echo "  ✓ PHP skripty nainstalovány"

cp src/opnsense/service/conf/actions.d/actions_devicemonitor.conf \
   /usr/local/opnsense/service/conf/actions.d/
echo "  ✓ Configd actions nainstalovány"

cp src/opnsense/service/templates/OPNsense/DeviceMonitor/* \
   /usr/local/opnsense/service/templates/OPNsense/DeviceMonitor/
echo "  ✓ Configd šablony nainstalovány"

echo 'devicemonitor_enable="YES"' > /etc/rc.conf.d/devicemonitor
chmod 644 /etc/rc.conf.d/devicemonitor
echo "  ✓ Autostart nastaven"

[ -f "/var/db/known_devices.db" ] && rm -f /var/db/known_devices.db

# ============================================
# [9/9] FINALIZACE A SPUŠTĚNÍ
# ============================================

echo ""
echo "[9/9] Finalizuji instalaci..."

echo "  → Čistím cache..."
rm -f /tmp/opnsense_menu_cache.xml
rm -f /tmp/opnsense_acl_cache.json
rm -rf /var/cache/opnsense/templates/* 2>/dev/null || true

echo "  → Aktualizuji menu a pluginy..."
/usr/local/etc/rc.configure_plugins

echo "  → Restartuji configd..."
service configd restart
sleep 3

# Vyrenderuje config.json a /etc/rc.conf.d/devicemonitor z config.xml
echo "  → Generuji konfiguraci ze šablon..."
configctl template reload OPNsense/DeviceMonitor
chmod 600 /var/db/devicemonitor/config.json 2>/dev/null || true

echo "  → Spouštím daemon..."
pkill -f monitor_daemon.py 2>/dev/null
sleep 1
rm -f /var/run/devicemonitor.pid
configctl devicemonitor start
sleep 2

PID=$(cat /var/run/devicemonitor.pid 2>/dev/null)
if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
    echo "  ✓ Daemon běží (PID: $PID)"
else
    echo "  ⚠ VAROVÁNÍ: Daemon se nepodařilo spustit"
    echo "  → Spusť ručně: configctl devicemonitor start"
    echo "  → Log: tail -f /var/log/devicemonitor.log"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ✓ Device Monitor v${PLUGIN_VER} úspěšně nainstalován!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

exit 0