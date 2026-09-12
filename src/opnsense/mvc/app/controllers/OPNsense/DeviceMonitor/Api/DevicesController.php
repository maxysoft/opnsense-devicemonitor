<?php

namespace OPNsense\DeviceMonitor\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\DeviceMonitor\DeviceMonitor;

/**
 * DevicesController
 * 
 * API controller pro správu zařízení
 */
class DevicesController extends ApiControllerBase
{
    private function getPaths()
    {
        $defaultsFile = '/usr/local/opnsense/mvc/app/models/OPNsense/DeviceMonitor/defaults.json';
        
        if (file_exists($defaultsFile)) {
            $defaults = json_decode(file_get_contents($defaultsFile), true);
            return $defaults['paths'];  // ← Cesty z defaults.json
        }
        
        // Fallback pokud defaults.json neexistuje
        return [-1];
    }

    /**
     * Aktualizace custom hostname
     * POST /api/devicemonitor/devices/updatehostname
     */
    public function updatehostnameAction()
    {
        if ($this->request->isPost()) {
            $mac = $this->request->getPost('mac');
            $hostname = $this->request->getPost('hostname');
            
            if (empty($mac)) {
                return ['result' => 'failed', 'error' => 'MAC required'];
            }
            
            $model = new DeviceMonitor();
            if ($model->updateHostname($mac, $hostname)) {
                return ['result' => 'saved'];
            }
        }
        return ['result' => 'failed'];
    }

    /**
     * Vyhledání zařízení (pro Bootgrid tabulku)
     * GET/POST /api/devicemonitor/devices/search
     */
    public function searchAction()
    {
        
        try {
            $model = new DeviceMonitor();
            $devices = $model->getDevices();
            
            // Zpracuj parametry z Bootgrid - bezpečně
            $current = 1;
            $rowCount = -1;
            $searchPhrase = '';
            $sort = [];
            
            if ($this->request->has('current')) {
                $current = intval($this->request->get('current'));
            }
            if ($this->request->has('rowCount')) {
                $rowCount = intval($this->request->get('rowCount'));
            }
            if ($this->request->has('searchPhrase')) {
                $searchPhrase = (string)$this->request->get('searchPhrase');
            }
            if ($this->request->has('sort')) {
                $sortData = $this->request->get('sort');
                if (is_array($sortData)) {
                    $sort = $sortData;
                }
            }
            
            // === 1. FILTROVÁNÍ ===
            if (!empty($searchPhrase) && strlen(trim($searchPhrase)) > 0) {
                $searchPhrase = strtolower(trim($searchPhrase));
                $filtered = [];
                
                foreach ($devices as $device) {
                    $match = false;
                    
                    // Kontrola všech polí
                    if (isset($device['mac']) && strpos(strtolower($device['mac']), $searchPhrase) !== false) {
                        $match = true;
                    }
                    if (isset($device['ip']) && strpos(strtolower($device['ip']), $searchPhrase) !== false) {
                        $match = true;
                    }
                    if (isset($device['hostname']) && strpos(strtolower($device['hostname']), $searchPhrase) !== false) {
                        $match = true;
                    }
                    if (isset($device['vendor']) && strpos(strtolower($device['vendor']), $searchPhrase) !== false) {
                        $match = true;
                    }
                    if (isset($device['vlan']) && strpos(strtolower($device['vlan']), $searchPhrase) !== false) {
                        $match = true;
                    }
                    if (isset($device['status']) && strpos(strtolower($device['status']), $searchPhrase) !== false) {
                        $match = true;
                    }
                    
                    if ($match) {
                        $filtered[] = $device;
                    }
                }
                
                $devices = $filtered;
            }
            
            $total = count($devices);
            
            // === 2. ŘAZENÍ ===
            if (!empty($sort) && is_array($sort)) {
                $sortColumn = key($sort);
                $sortOrder = $sort[$sortColumn];
                
                if ($sortColumn && in_array($sortColumn, ['mac', 'ip', 'hostname', 'vendor', 'vlan', 'first_seen', 'last_seen', 'status'])) {
                    usort($devices, function($a, $b) use ($sortColumn, $sortOrder) {
                        $valA = isset($a[$sortColumn]) ? $a[$sortColumn] : '';
                        $valB = isset($b[$sortColumn]) ? $b[$sortColumn] : '';
                        
                        // Porovnání
                        if ($valA == $valB) {
                            return 0;
                        }
                        
                        $result = ($valA < $valB) ? -1 : 1;
                        
                        // Podle směru řazení
                        return ($sortOrder === 'desc') ? -$result : $result;
                    });
                }
            }
            
            // === 3. STRÁNKOVÁNÍ ===
            if ($rowCount > 0) {
                $offset = ($current - 1) * $rowCount;
                $devices = array_slice($devices, $offset, $rowCount);
            }
            
            // Re-index pole (bootgrid vyžaduje indexed array)
            $devices = array_values($devices);
            
            return [
                'rows' => $devices,
                'rowCount' => count($devices),
                'total' => $total,
                'current' => $current
            ];
            
        } catch (\Exception $e) {
            // V případě chyby vrať prázdná data
            return [
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Statistiky zařízení
     * GET /api/devicemonitor/devices/stats
     */
    public function statsAction()
    {
        
        $paths = $this->getPaths();
        $result = ['total' => 0, 'online' => 0];
        
        try {
            if (file_exists($paths['dbFile'])) {
                $db = new \SQLite3($paths['dbFile']);
                
                $result['total'] = (int)$db->querySingle(
                    "SELECT COUNT(*) FROM devices"
                );
                
                $result['online'] = (int)$db->querySingle(
                    "SELECT COUNT(*) FROM devices WHERE is_active = 1"
                );
                
                $db->close();
            }
        } catch (\Exception $e) {
            syslog(LOG_ERR, "DeviceMonitor stats error: " . $e->getMessage());
        }
        
        return $result;
    }

    /**
     * Smazání jednoho zařízení
     * POST /api/devicemonitor/devices/delete
     */
    public function deleteAction()
    {
        if ($this->request->isPost()) {
            $mac = $this->request->getPost('mac');
            $model = new DeviceMonitor();

            if ($model->deleteDevice($mac)) {
                return ['result' => 'deleted'];
            }
        }

        return ['result' => 'failed'];
    }

    /**
     * Ping zařízení a aktualizuj jeho status
     * POST /api/devicemonitor/devices/pingdevice
     */
    public function pingdeviceAction()
    {
        if ($this->request->isPost()) {
            $ip  = $this->request->getPost('ip',  'string', '');
            $mac = $this->request->getPost('mac',  'string', '');

            if (empty($ip) || empty($mac)) {
                return ['result' => 'failed', 'error' => 'IP and MAC required'];
            }

            // Validace IP adresy
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return ['result' => 'failed', 'error' => 'Invalid IP'];
            }

            // Ping - 2 pakety, timeout 1s
            exec('ping -c 2 -W 1 ' . escapeshellarg($ip) . ' > /dev/null 2>&1', $out, $ret);
            $online = ($ret === 0) ? 1 : 0;

            // Aktualizuj DB
            $paths = $this->getPaths();
            try {
                $db = new \SQLite3($paths['dbFile']);
                $stmt = $db->prepare(
                    'UPDATE devices SET is_active = :active WHERE mac = :mac'
                );
                $stmt->bindValue(':active', $online, SQLITE3_INTEGER);
                $stmt->bindValue(':mac',    $mac,    SQLITE3_TEXT);
                $stmt->execute();
                $db->close();
            } catch (\Exception $e) {
                return ['result' => 'failed', 'error' => $e->getMessage()];
            }

            return [
                'result' => $online ? 'online' : 'offline',
                'ip'     => $ip,
                'mac'    => $mac
            ];
        }
        return ['result' => 'failed'];
    }

    /**
     * Vyčištění celé databáze
     * POST /api/devicemonitor/devices/clear
     */
    public function clearAction()
    {
        if ($this->request->isPost()) {
            $model = new DeviceMonitor();
            // Report what actually happened. Discarding this made a failed
            // clear indistinguishable from a successful one.
            if ($model->clearAll()) {
                return ['result' => 'cleared'];
            }
        }

        return ['result' => 'failed'];
    }

    /**
     * Notifications whose delivery failed and that are waiting for a retry
     * GET /api/devicemonitor/devices/queue
     */
    public function queueAction()
    {
        $paths = $this->getPaths();
        $rows = [];
        $state = ['attempts' => 0, 'next_attempt' => '', 'last_error' => ''];

        try {
            if (file_exists($paths['dbFile'])) {
                $db = new \SQLite3($paths['dbFile'], SQLITE3_OPEN_READONLY);
                // Without this a failed statement only raises a warning and
                // returns false, which the catch below would never see.
                $db->enableExceptions(true);
                $db->busyTimeout(2000);

                // The scanner creates this table, so it is absent until the
                // first scan runs after an upgrade.
                $exists = $db->querySingle(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='notification_queue'"
                );

                if (!empty($exists)) {
                    $result = $db->query(
                        'SELECT id, channel, event, macs, created_at'
                        . ' FROM notification_queue ORDER BY id'
                    );
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        $macs = json_decode((string)$row['macs'], true);
                        $macs = is_array($macs) ? $macs : [];
                        $rows[] = [
                            'id' => (int)$row['id'],
                            'channel' => (string)$row['channel'],
                            'event' => (string)$row['event'],
                            'devices' => count($macs),
                            'macs' => implode(', ', array_slice($macs, 0, 5)),
                            'created_at' => DeviceMonitor::displayTime(
                                $row['created_at'] ?? '', 'Y-m-d H:i:s'),
                        ];
                    }

                    // The retry schedule covers the queue as a whole, so it
                    // is reported once rather than per entry.
                    $retry = $db->querySingle(
                        'SELECT attempts, next_attempt, last_error FROM notification_retry'
                        . ' WHERE id = 1',
                        true
                    );
                    if (is_array($retry)) {
                        $state = [
                            'attempts' => (int)$retry['attempts'],
                            'next_attempt' => (float)$retry['next_attempt'] > 0
                                ? date('Y-m-d H:i:s', (int)$retry['next_attempt'])
                                : '',
                            'last_error' => (string)($retry['last_error'] ?? ''),
                        ];
                    }
                }

                $db->close();
            }
        } catch (\Exception $e) {
            return ['rows' => [], 'total' => 0, 'state' => $state, 'error' => $e->getMessage()];
        }

        return ['rows' => $rows, 'total' => count($rows), 'state' => $state];
    }

    /**
     * Retry every queued notification now instead of waiting out its delay
     * POST /api/devicemonitor/devices/retryqueue
     */
    public function retryqueueAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        $paths = $this->getPaths();

        if (!file_exists($paths['dbFile'])) {
            return ['result' => 'failed', 'error' => 'no database yet'];
        }

        try {
            $db = new \SQLite3($paths['dbFile']);
            $db->enableExceptions(true);
            $db->busyTimeout(2000);
            $db->query('UPDATE notification_retry SET attempts = 0, next_attempt = 0');
            $db->close();
        } catch (\Exception $e) {
            return ['result' => 'failed', 'error' => $e->getMessage()];
        }

        // Through configd, because the notify scripts write a root-owned log.
        $backend = new \OPNsense\Core\Backend();
        $backend->configdRun('devicemonitor processQueue');

        return $this->queueAction();
    }

    /**
     * Drop every queued notification
     * POST /api/devicemonitor/devices/discardqueue
     */
    public function discardqueueAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        $paths = $this->getPaths();

        if (!file_exists($paths['dbFile'])) {
            return ['result' => 'failed', 'error' => 'no database yet'];
        }

        try {
            $db = new \SQLite3($paths['dbFile']);
            $db->enableExceptions(true);
            $db->busyTimeout(2000);
            $db->query('DELETE FROM notification_queue');
            $db->query('UPDATE notification_retry SET attempts = 0, next_attempt = 0');
            $db->close();
        } catch (\Exception $e) {
            return ['result' => 'failed', 'error' => $e->getMessage()];
        }

        return ['result' => 'discarded'];
    }
}