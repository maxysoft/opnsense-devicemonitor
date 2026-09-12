<?php
/**
 * Payload builders for the webhook channel.
 *
 * No OPNsense dependency on purpose: the caller resolves interface labels
 * and passes plain rows, so tests/ can exercise this without the class tree.
 */

class WebhookPayload
{
    /** Apprise severity per event; each service renders it its own way. */
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
     * @param string $event    new, up or down
     * @param array  $rows     already-sliced devices with mac/vendor/ip/iface
     * @param string $hostname firewall reporting the event
     * @param int    $total    devices in the dispatch, to count those left out
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
