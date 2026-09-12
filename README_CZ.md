# OPNsense Device Monitor

**[🇬🇧 English](README.md)**

Sleduje zařízení v síti pomocí databáze host discovery přímo v OPNsense a
upozorní vás, když se objeví nové zařízení nebo když se některé připojí či
odpojí.

Fork projektu [hacesoft/opnsense-devicemonitor](https://github.com/hacesoft/opnsense-devicemonitor),
zabalený jako instalovatelný plugin pro OPNsense.

> **Upozornění k AI.** Části tohoto forku, včetně balíčkování a některých
> funkcí, vznikly s pomocí AI a před vydáním je zkontroloval člověk. Plugin
> běží na firewallu: přečtěte si kód, otestujte ho tam, kde si můžete dovolit
> chybu, a nahlaste cokoli, co vypadá špatně.
>
> Tento soubor je zkrácený přehled. Úplná dokumentace je v anglickém
> [README.md](README.md).

## Co plugin umí

- Objevování zařízení z hostwatch databáze OPNsense (`/var/db/hostwatch/hosts.db`)
- E-mailové notifikace přes lokální sendmail nebo přímé SMTP
- Webhooky: ntfy, Discord, Apprise API nebo obecný JSON
- Fronta doručení: neúspěšná notifikace se opakuje, dokud ji cíl nepřijme
- Události pro nové zařízení i pro odpojení a opětovné připojení
- Filtrování podle rozhraní zvlášť pro sken, e-mail a webhooky
- Štítky NEW a RESERVED, vlastní názvy zařízení, export do CSV
- Widget na Lobby dashboardu a ovládání služby v Diagnostics → Services

## Požadavky

OPNsense 26.1.5 nebo novější.

## Instalace

Jednou přidejte repozitář jako root přes SSH:

```bash
fetch -o /usr/local/etc/pkg/repos/mxy-opnsense-repo.conf \
  https://maxysoft.github.io/opnsense-repo/mxy-opnsense-repo.conf
pkg update
```

Plugin pak nainstalujte v **System → Firmware → Plugins** (hledejte
`os-devicemonitor`), nebo z příkazové řádky:

```bash
pkg install os-devicemonitor
```

Aktualizace: `pkg upgrade os-devicemonitor`. Odinstalace: `pkg remove
os-devicemonitor`; databáze v `/var/db/devicemonitor` zůstane zachována.

## Nastavení

Vše najdete v Services → Device Monitor → Settings.

Základní volby jsou interval skenu, úroveň logování a výběr rozhraní, která se
mají sledovat. E-mail i webhook se zapínají samostatně a každý má vlastní filtr
rozhraní. U webhooku nastavte **Webhook type** ručně; detekce podle URL je jen
záloha a u vlastního ntfy serveru s jiným jménem hostitele hádá špatně.

Certifikát vydaný certifikační autoritou tohoto firewallu je důvěryhodný
automaticky, pokud je autorita v **System → Trust → Authorities**. Volbu
**Skip TLS verification** použijte jen jako poslední možnost: URL webhooku bývá
sama o sobě přihlašovací údaj.

Neúspěšné doručení se neztratí. Událost jde do fronty a ta se opakuje jako
celek: 30s, 30s, 1m, 3m, 5m, 10m, 30m a pak každou hodinu. Na stránce Devices
se zobrazí panel **Pending notifications** s důvodem selhání a tlačítky pro
okamžité zopakování nebo zahození.

## Stav online

Seznam vychází z hostwatch, který zaznamená zařízení pokaždé, když ho uvidí v
síti (ARP, NDP, DHCP, DNS). Online je zařízení viděné v posledních 15 minutách,
takže nezáleží na tom, jestli odpovídá na ping.

Tlačítko **Check online** také nespoléhá na ICMP: pošle jeden paket, aby
firewall adresu přeložil, a pak zařízení vyhledá v ARP/NDP tabulce sousedů.
Zařízení, které ping zahazuje, musí na ARP odpovědět, jinak se k němu žádný
provoz nedostane. Zařízení za routerem se v této tabulce neobjeví.

## Řešení potíží

```bash
tail -f /var/log/devicemonitor.log
grep WEBHOOK /var/log/devicemonitor.log
```

Podrobnější výpis zapnete volbou **Log level** = `debug`. Log rotuje newsyslog
po 1 MB, uchovává 7 generací.

## Kredity

Původní plugin: [Hacesoft](https://github.com/hacesoft) ([hacesoft.cz](https://hacesoft.cz)).
Tento fork doplnil balíčkování, frontu notifikací a další funkce.

Historie verzí je v [releases](https://github.com/maxysoft/opnsense-repo/releases).

## Licence

BSD 2-Clause — viz [LICENSE](LICENSE).
