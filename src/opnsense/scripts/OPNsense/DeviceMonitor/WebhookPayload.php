<?php
/**
 * Payload builders for the webhook channel
 * Path: /usr/local/opnsense/scripts/OPNsense/DeviceMonitor/WebhookPayload.php
 *
 * Deliberately free of any OPNsense dependency: the caller resolves interface
 * labels and passes plain rows, so tests/test_apprise_payload.php can exercise
 * the wording and the event mapping without the firewall's class tree.
 */

class WebhookPayload
{
    /**
     * Apprise carries the severity in a "type" field, which each target
     * service renders its own way (ntfy priority, Discord colour, mail
     * subject prefix). Anything unknown degrades to info rather than failing.
     */
    const TYPES = [
        'new'  => 'info',
        'up'   => 'success',
        'down' => 'warning',
    ];

    const HEADLINES = [
        'new'  => ['🔔', 'new device(s) detected'],
        'up'   => ['🟢', 'device(s) came online'],
        'down' => ['⚫', 'device(s) went offline'],
    ];

    /**
     * Build the body Apprise expects.
     *
     * @param string $event   new, up or down
     * @param array  $rows    already-sliced devices, each with mac/vendor/ip/iface
     * @param string $hostname firewall reporting the event
     * @param int    $total   devices in the whole dispatch, to say how many were left out
     */
    public static function apprise($event, array $rows, $hostname, $total = null)
    {
        list($icon, $headline) = self::HEADLINES[$event] ?? self::HEADLINES['new'];
        $total = $total === null ? count($rows) : (int)$total;

        $lines = [sprintf('**%d %s** on `%s`', $total, $headline, $hostname), ''];
        foreach ($rows as $row) {
            $iface = (string)($row['iface'] ?? '');
            $lines[] = sprintf(
                '- `%s` %s — %s%s',
                (string)($row['mac'] ?? ''),
                (string)($row['vendor'] ?? 'Unknown vendor'),
                (string)($row['ip'] ?? ''),
                $iface !== '' ? " ({$iface})" : ''
            );
        }
        $omitted = $total - count($rows);
        if ($omitted > 0) {
            $lines[] = '';
            $lines[] = sprintf('_… and %d more_', $omitted);
        }

        return [
            'title'  => sprintf('%s OPNsense: %d %s', $icon, $total, $headline),
            'body'   => implode("\n", $lines),
            'type'   => self::TYPES[$event] ?? self::TYPES['new'],
            'format' => 'markdown',
        ];
    }

    /** The Test webhook button, which has no devices to report. */
    public static function appriseTest($hostname)
    {
        return [
            'title'  => '🧪 OPNsense Device Monitor',
            'body'   => sprintf("Device Monitor webhook is working on `%s`. ✅", $hostname),
            'type'   => 'success',
            'format' => 'markdown',
        ];
    }
}
