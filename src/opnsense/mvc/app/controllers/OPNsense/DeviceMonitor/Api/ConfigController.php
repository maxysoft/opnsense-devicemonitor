<?php

namespace OPNsense\DeviceMonitor\Api;

use OPNsense\Base\ApiControllerBase;

require_once('/usr/local/opnsense/scripts/OPNsense/DeviceMonitor/NotificationHandler.php');

/**
 * Endpoints that are not plain settings.
 *
 * Reading and writing the configuration itself lives in SettingsController,
 * which is backed by the General model.
 */
class ConfigController extends ApiControllerBase
{
    /**
     * Version of the installed package, as recorded by the plugin framework.
     */
    public function getversionAction()
    {
        $versionFile = '/usr/local/opnsense/version/devicemonitor';
        if (is_readable($versionFile)) {
            $info = json_decode((string)@file_get_contents($versionFile), true);
            if (!empty($info['product_version'])) {
                return ['version' => $info['product_version']];
            }
        }

        return ['version' => 'unknown'];
    }

    /**
     * Interface labels the scanner can produce, so the notification filters
     * can be filled in with values that actually match.
     */
    public function getinterfacesAction()
    {
        $result = [];
        try {
            $xml = @simplexml_load_file('/conf/config.xml');
            if ($xml && isset($xml->interfaces)) {
                foreach ($xml->interfaces->children() as $ifName => $ifData) {
                    if (empty(trim((string)($ifData->if ?? '')))) {
                        continue;
                    }
                    $descr = trim((string)($ifData->descr ?? ''));
                    // Keyed by the OPNsense interface name, which is what the
                    // scanner stores against each device and what the
                    // InterfaceField dropdowns submit.
                    $result[$ifName] = $descr !== '' ? $descr : strtoupper($ifName);
                }
            }
        } catch (\Exception $e) {
            // an unreadable config.xml just means no suggestions
        }
        return $result;
    }

    public function testemailAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => gettext('POST required')];
        }

        $handler = new \NotificationHandler();
        $handler->fLog("Preparing to send test email", 'EMAIL');
        $result = $handler->sendEmail(true);

        $ok = in_array($result['result'] ?? '', ['sent', 'ok'], true);
        $logMessage = "Test email result: " . ($ok ? "SUCCESS" : "FAILED");
        if (!$ok) {
            $logMessage .= " | Reason: " . ($result['message'] ?? 'Unknown error');
        }
        $handler->fLog($logMessage, "EMAIL-ConfigController");
        return $result;
    }

    public function testWebhookAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => gettext('POST required')];
        }

        $handler = new \NotificationHandler();
        $handler->fLog("Preparing to send test webhook", 'WEBHOOK');
        $result = $handler->sendWebhook(true, $this->request->getPost('webhook_url', 'string', ''));

        $ok = in_array($result['result'] ?? '', ['sent', 'ok'], true);
        $logMessage = "Test webhook result: " . ($ok ? "SUCCESS" : "FAILED");
        if (!$ok) {
            $logMessage .= " | Reason: " . ($result['message'] ?? 'Unknown error');
        }
        $handler->fLog($logMessage, "WEBHOOK-ConfigController");
        return $result;
    }
}
