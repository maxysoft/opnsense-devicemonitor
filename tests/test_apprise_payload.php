<?php
/**
 * Checks the Apprise payload builder. Run by CI inside the FreeBSD build VM,
 * where php is already installed:
 *
 *   php tests/test_apprise_payload.php
 */

require_once(__DIR__ . '/../src/opnsense/scripts/OPNsense/DeviceMonitor/WebhookPayload.php');

$failures = 0;

function check($name, $condition)
{
    global $failures;
    if ($condition) {
        echo "ok   {$name}\n";
    } else {
        echo "FAIL {$name}\n";
        $failures++;
    }
}

$rows = [
    ['mac' => 'aa:bb:cc:dd:ee:01', 'vendor' => 'Apple, Inc.', 'ip' => '192.168.1.10', 'iface' => 'LAN'],
    ['mac' => 'aa:bb:cc:dd:ee:02', 'vendor' => 'Espressif', 'ip' => '192.168.1.11', 'iface' => 'IOT'],
];

$new = WebhookPayload::apprise('new', $rows, 'fw01', 2);

check('body is the only mandatory field and is populated', !empty($new['body']));
check('payload carries exactly the fields Apprise accepts', array_keys($new) === ['title', 'body', 'type', 'format']);
check('markdown is declared, since the body uses it', $new['format'] === 'markdown');
check('a new device is informational', $new['type'] === 'info');
check('the title states the count', strpos($new['title'], '2 new device(s) detected') !== false);
check('every device appears in the body', strpos($new['body'], 'aa:bb:cc:dd:ee:01') !== false
    && strpos($new['body'], 'aa:bb:cc:dd:ee:02') !== false);
check('the interface label is rendered, not the raw key', strpos($new['body'], '(LAN)') !== false);
check('nothing is reported as omitted when the list is complete',
    strpos($new['body'], 'and') === false || strpos($new['body'], 'more') === false);

// A dispatch larger than the slice has to say so, or the message silently
// under-reports the event.
$capped = WebhookPayload::apprise('new', $rows, 'fw01', 7);
check('devices left out of the slice are counted', strpos($capped['body'], '_… and 5 more_') !== false);

check('coming back online is a success', WebhookPayload::apprise('up', $rows, 'fw01', 2)['type'] === 'success');
check('going offline is a warning', WebhookPayload::apprise('down', $rows, 'fw01', 2)['type'] === 'warning');
check('an unknown event degrades to info rather than failing',
    WebhookPayload::apprise('sideways', $rows, 'fw01', 2)['type'] === 'info');

// Rows arrive straight from SQLite, where any column can be NULL.
$sparse = WebhookPayload::apprise('new', [['mac' => 'aa:bb:cc:dd:ee:03']], 'fw01', 1);
check('a row missing every optional column still renders', strpos($sparse['body'], 'aa:bb:cc:dd:ee:03') !== false);
check('a missing vendor is named, not blank', strpos($sparse['body'], 'Unknown vendor') !== false);
check('a missing interface leaves no empty brackets', strpos($sparse['body'], '()') === false);

$test = WebhookPayload::appriseTest('fw01');
check('the test payload is a success notice', $test['type'] === 'success');
check('the test payload names the firewall', strpos($test['body'], 'fw01') !== false);

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
