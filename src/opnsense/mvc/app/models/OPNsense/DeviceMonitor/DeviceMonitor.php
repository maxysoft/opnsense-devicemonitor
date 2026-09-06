<?php

namespace OPNsense\DeviceMonitor;

class DeviceMonitor
{
    // ================================================================
    // CESTY - VŠECHNO NA JEDNOM MÍSTĚ!
    //
    //          Ukazatel na konfigurační soubor s výchozími hodnotami
    //
    // ================================================================
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
     * OPNsense interface key => description. The scanner stores the key
     * against each device, which is what the filters match on, but "opt2"
     * means nothing to a reader.
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

    /**
     * Readable label for a stored interface key. Devices on an unassigned
     * interface keep a generated label instead of a key, so anything unknown
     * is passed through unchanged.
     */
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
            
            // Merge saved values over current defaults. This makes newly
            // added settings (for example Direct SMTP) available immediately
            // after an upgrade without deleting the existing config.json.
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


    // ========================================
    // GETTERY PRO CESTY (pro Controllery)
    // ========================================


    /**
     * Vrátí cestu k PID souboru
     */
    public function getPidFilePath()
    {
        return self::getPath('pidFile');
    }

    
    /**
     * Vrátí cestu k databázi
     */
    public function getDbFilePath()
    {
        return self::getPath('dbFile');
    }
    
    /**
     * Vrátí cestu ke konfiguračnímu souboru
     */
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


    // Settings are stored in config.xml through the General model and rendered
    // into config.json by the configd template, so nothing writes that file
    // from PHP any more. getConfig() above is the read side, used by the
    // notification scripts.

    // ========================================
    // DATABÁZE
    // ========================================
    
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

    // ========================================
    // ZAŘÍZENÍ - CRUD OPERACE
    // ========================================
    
    /**
     * Získání všech zařízení z databáze
     * @return array Seznam zařízení (upravený podle konfigurace)
     */
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

                // Formátuj datum do českého formátu: 29.12.2025 - 18:37:51
                if (!empty($row['last_seen'])) {
                    $timestamp = strtotime($row['last_seen']);
                    if ($timestamp !== false) {
                        $row['last_seen'] = date('d.m.Y - H:i:s', $timestamp);
                    }
                }
                
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
            // Treat Clear All like repeated manual deletion: remember the
            // current last_seen value for every device so old Hostwatch
            // history does not immediately repopulate the table.
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