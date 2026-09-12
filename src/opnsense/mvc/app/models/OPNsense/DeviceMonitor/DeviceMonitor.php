<?php

namespace OPNsense\DeviceMonitor;

class DeviceMonitor
{
    private static $defaultsFile = '/usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/defaults.json';
    private static $data = null;

    private static function loadDefaults()
    {
        if (self::$data === null) {
            $json = file_get_contents(self::$defaultsFile);
            self::$data = json_decode($json, true);
        }
        return self::$data;
    }

    public static function getPaths()
    {
        $data = self::loadDefaults();
        return $data['paths'];
    }
    
    public static function getPath($key)
    {
        $paths = self::getPaths();
        return isset($paths[$key]) ? $paths[$key] : null;
    }

    private static $interfaceNames = null;

    /**
     * OPNsense interface key => description. Devices are stored against the
     * key, which is what the filters match, but "opt2" means nothing to a reader.
     */
    public static function getInterfaceNames()
    {
        if (self::$interfaceNames !== null) {
            return self::$interfaceNames;
        }

        $names = [];
        try {
            $xml = @simplexml_load_file('/conf/config.xml');
            if ($xml && isset($xml->interfaces)) {
                foreach ($xml->interfaces->children() as $key => $node) {
                    if (trim((string)($node->if ?? '')) === '') {
                        continue;
                    }
                    $descr = trim((string)($node->descr ?? ''));
                    $names[(string)$key] = $descr !== '' ? $descr : strtoupper((string)$key);
                }
            }
        } catch (\Exception $e) {
            // Without names the key is still shown, which is not fatal.
        }

        self::$interfaceNames = $names;
        return $names;
    }

    /** Readable label for an interface key; unknown values pass through. */
    public static function describeInterface($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return '-';
        }
        $names = self::getInterfaceNames();
        return isset($names[$key]) ? $names[$key] : $key;
    }

    public static function getConfig()
    {
        $data = self::loadDefaults();
        $configFilePath = $data['paths']['configFile'];
        
        // Pokud existuje config.json, načti z něj
        if (file_exists($configFilePath)) {
            $json = file_get_contents($configFilePath);
            $savedConfig = json_decode($json, true);
            
            // Saved values over current defaults, so a setting added by an upgrade
            // is available without deleting config.json.
            if ($savedConfig !== null && is_array($savedConfig)) {
                unset($savedConfig['paths']);
                $config = array_merge($data['config'], $savedConfig);
                $config['paths'] = $data['paths'];
                return $config;
            }
        }
        
        // Jinak vrať defaults
        $config = $data['config'];
        $config['paths'] = $data['paths'];
        return $config;
    }



    /** Path to the daemon pidfile. */
    public function getPidFilePath()
    {
        return self::getPath('pidFile');
    }

    
    /** Path to the device database. */
    public function getDbFilePath()
    {
        return self::getPath('dbFile');
    }
    
    /** Path to the rendered configuration. */
    public function getConfigFilePath()
    {
        return self::getPath('configFile');
    }
    
    public function updateHostname($mac, $hostname)
    {
        $db = $this->getDb();
        $hostname = trim($hostname);
        
        if ($hostname === '') {
            $stmt = $db->prepare('UPDATE devices SET custom_hostname = NULL WHERE mac = :mac');
            $stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
        } else {
            $stmt = $db->prepare('UPDATE devices SET custom_hostname = :hn, hostname = :hn WHERE mac = :mac');
            $stmt->bindValue(':hn', $hostname, SQLITE3_TEXT);
            $stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
        }
        
        $stmt->execute();
        $changes = $db->changes();
        $db->close();
        return $changes > 0;
    }


    // Settings live in config.xml and are rendered into config.json by the
    // configd template; getConfig() above is the read side.

    private function getDb()
    {
        $file_mame = self::getPath('dbFile');
        $dbDir = dirname($file_mame);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        if (!file_exists($file_mame)) {
            $this->initDatabase();
        }

        $db = new \SQLite3($file_mame);
        $db->busyTimeout(5000);

        // Migration for existing installations: getDb() is also used by GUI
        // actions, so do not rely on scan_network.py having run first.
        $db->exec('CREATE TABLE IF NOT EXISTS deleted_devices (
            mac TEXT PRIMARY KEY,
            last_seen DATETIME,
            deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        return $db;
    }


    private function initDatabase()
    {
        $file_mame = self::getPath('dbFile');
        $db = new \SQLite3($file_mame);
        
        $db->exec('CREATE TABLE IF NOT EXISTS devices (
            mac TEXT PRIMARY KEY,
            ip TEXT,
            hostname TEXT,
            vendor TEXT,
            vlan TEXT,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            notified INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 0,
            notification_pending INTEGER DEFAULT 0
        )');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_last_seen ON devices(last_seen)');

        $db->exec('CREATE TABLE IF NOT EXISTS deleted_devices (
            mac TEXT PRIMARY KEY,
            last_seen DATETIME,
            deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        // Migration: add columns for older databases
        @$db->exec('ALTER TABLE devices ADD COLUMN first_seen DATETIME DEFAULT CURRENT_TIMESTAMP');
        @$db->exec('ALTER TABLE devices ADD COLUMN custom_hostname TEXT DEFAULT NULL');
        
        $db->close();
        chmod($file_mame, 0644);
    }

    /**
     * Získání všech zařízení z databáze
     * @return array Seznam zařízení (upravený podle konfigurace)
     */
    /**
     * Normalise a MAC for comparison: arp(8) prints an octet without its
     * leading zero, while the scanner stores it padded.
     */
    public static function normaliseMac($mac)
    {
        $parts = explode(':', strtolower(trim((string)$mac)));
        if (count($parts) !== 6) {
            return '';
        }
        foreach ($parts as $i => $part) {
            if (!preg_match('/^[0-9a-f]{1,2}$/', $part)) {
                return '';
            }
            $parts[$i] = str_pad($part, 2, '0', STR_PAD_LEFT);
        }
        return implode(':', $parts);
    }

    /**
     * MACs and addresses with a usable neighbour entry, from arp(8) and ndp(8).
     * This is what the DHCP leases pages call "online": a device must answer ARP,
     * or NDP on IPv6, to receive any traffic, while many drop ICMP by policy.
     * Incomplete and expired entries mean no answer, so they are skipped.
     */
    public static function parseNeighbours($arpJson, $ndpOutput)
    {
        $macs = [];
        $ips = [];

        $arp = json_decode((string)$arpJson, true);
        foreach ($arp['arp']['arp-cache'] ?? [] as $entry) {
            if (!empty($entry['incomplete']) || !empty($entry['expired'])) {
                continue;
            }
            $mac = self::normaliseMac($entry['mac-address'] ?? '');
            if ($mac !== '') {
                $macs[] = $mac;
            }
            if (!empty($entry['ip-address'])) {
                $ips[] = strtolower((string)$entry['ip-address']);
            }
        }

        // ndp(8) has no JSON output. Its first line is a header whose second
        // column is not a MAC, so it drops out with the incomplete entries.
        foreach (preg_split('/\r?\n/', (string)$ndpOutput) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3) {
                continue;
            }
            $mac = self::normaliseMac($parts[1]);
            if ($mac === '') {
                continue;
            }
            $macs[] = $mac;
            // fe80::1%igc0 -> fe80::1, or nothing would ever match.
            $ips[] = strtolower(explode('%', $parts[0])[0]);
        }

        return [
            'macs' => array_values(array_unique($macs)),
            'ips' => array_values(array_unique($ips)),
        ];
    }

    /**
     * Render a stored timestamp in the firewall's timezone. The scanner writes
     * UTC, and date.timezone here is the configured one, so parsing without
     * saying UTC would shift every value by the local offset.
     */
    public static function displayTime($value, $format = 'd.m.Y - H:i:s')
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? $value : date($format, $timestamp);
    }

    public function getDevices()
    {
        $devices = [];
        $file_mame = self::getPath('dbFile');
        
        if (file_exists($file_mame)) {
            $db = new \SQLite3($file_mame);
            $result = $db->query('SELECT * FROM devices ORDER BY last_seen DESC');
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                
                // Status podle is_active sloupce (místo času)
                $row['status'] = (isset($row['is_active']) && $row['is_active'] == 1) ? 'online' : 'offline';
                
                // Vendor může být NULL - oprav to
                if (empty($row['vendor'])) {
                    $row['vendor'] = 'Unknown';
                }

                // The scanner adds is_reserved on every run, but a database
                // written by an older version has not been through a scan yet.
                $row['is_reserved'] = isset($row['is_reserved']) ? (int)$row['is_reserved'] : 0;

                // Flag devices first seen within the last day. time() is UTC, so the
                // stored value has to be parsed as UTC too.
                $row['is_new'] = 0;
                if (!empty($row['first_seen'])) {
                    $firstSeen = strtotime($row['first_seen'] . ' UTC');
                    if ($firstSeen !== false && (time() - $firstSeen) < 86400) {
                        $row['is_new'] = 1;
                    }
                }

                // Sort on the instant: the rendered form starts with the day of month.
                $row['last_seen_ts'] = !empty($row['last_seen'])
                    ? (int)strtotime($row['last_seen'] . ' UTC') : 0;

                // Formátuj datum do českého formátu: 29.12.2025 - 18:37:51
                $row['last_seen'] = self::displayTime($row['last_seen'] ?? '');
                $row['first_seen'] = self::displayTime($row['first_seen'] ?? '');
                
                $devices[] = $row;
            }
            
            $db->close();
        }
        
        return $devices;
    }

    public function deleteDevice($mac)
    {
        $db = $this->getDb();
        $mac = strtolower(trim($mac));

        $db->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            $stmt = $db->prepare('SELECT last_seen FROM devices WHERE mac = :mac');
            $stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

            if (!$row) {
                $db->exec('ROLLBACK');
                $db->close();
                return false;
            }

            // Remember the newest Hostwatch timestamp already represented by
            // this row. The same historical record must not recreate it.
            $stmt = $db->prepare('INSERT OR REPLACE INTO deleted_devices (mac, last_seen, deleted_at) VALUES (:mac, :last_seen, CURRENT_TIMESTAMP)');
            $stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
            $stmt->bindValue(':last_seen', $row['last_seen'] ?? '', SQLITE3_TEXT);
            $stmt->execute();

            $stmt = $db->prepare('DELETE FROM devices WHERE mac = :mac');
            $stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
            $stmt->execute();
            $changes = $db->changes();

            $db->exec('COMMIT');
            $db->close();
            return $changes > 0;
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            $db->close();
            return false;
        }
    }

    public function clearAll()
    {
        $db = $this->getDb();
        $db->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            // Tombstone every device, or Hostwatch history repopulates the table.
            $db->exec('INSERT OR REPLACE INTO deleted_devices (mac, last_seen, deleted_at) SELECT mac, last_seen, CURRENT_TIMESTAMP FROM devices');
            $db->exec('DELETE FROM devices');
            $db->exec('COMMIT');
            $db->close();
            return true;
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            $db->close();
            return false;
        }
    }
}