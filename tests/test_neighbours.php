<?php
/**
 * Checks how arp(8) and ndp(8) output is read, which decides whether a device
 * is reported as reachable. Run by CI inside the FreeBSD build VM:
 *
 *   php tests/test_neighbours.php
 */

require_once(__DIR__ . '/../src/opnsense/mvc/app/models/OPNsense/DeviceMonitor/DeviceMonitor.php');

use OPNsense\DeviceMonitor\DeviceMonitor;

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

// Shapes taken from arp(8) --libxo json and ndp(8) -an on FreeBSD.
$arp = json_encode(['arp' => ['arp-cache' => [
    // arp(8) drops the leading zero of an octet; the scanner stores it padded.
    ['ip-address' => '192.168.1.1', 'mac-address' => '0:11:22:33:44:55', 'interface' => 'igc0'],
    // Asked and never answered, or answered too long ago: not reachable.
    ['ip-address' => '192.168.1.50', 'mac-address' => 'aa:bb:cc:dd:ee:ff', 'expired' => true],
    ['ip-address' => '192.168.1.51', 'mac-address' => '(incomplete)', 'incomplete' => true],
    ['ip-address' => '192.168.1.52', 'mac-address' => 'AA:BB:CC:11:22:33', 'permanent' => true],
    // The same host can appear once per interface.
    ['ip-address' => '192.168.1.1', 'mac-address' => '00:11:22:33:44:55', 'interface' => 'igc1'],
]]]);

$ndp = "Neighbor                        Linklayer Address  Netif Expire    S Flags\n"
     . "fe80::1%igc0                    0:1:2:3:4:5        igc0  23h59m56s S R\n"
     . "2001:db8::5%igc0                (incomplete)       igc0  expired   N\n";

$n = DeviceMonitor::parseNeighbours($arp, $ndp);

check('an unpadded MAC is padded to match the database', in_array('00:11:22:33:44:55', $n['macs'], true));
check('an expired entry is not reachable', !in_array('aa:bb:cc:dd:ee:ff', $n['macs'], true));
check('an incomplete entry is not reachable', !in_array('192.168.1.51', $n['ips'], true));
check('a permanent entry counts, lowercased', in_array('aa:bb:cc:11:22:33', $n['macs'], true));
check('the same host on two interfaces is listed once',
    count(array_keys($n['macs'], '00:11:22:33:44:55', true)) === 1);

check('an NDP neighbour is read', in_array('00:01:02:03:04:05', $n['macs'], true));
check('the NDP header is not mistaken for a host', !in_array('linklayer', $n['macs'], true));
check('an incomplete NDP entry is skipped', !in_array('2001:db8::5', $n['ips'], true));
check('the NDP zone index is stripped, so addresses compare',
    in_array('fe80::1', $n['ips'], true));

check('IPv4 addresses are listed', in_array('192.168.1.1', $n['ips'], true));

// Nothing here runs as root, and either command can be missing or fail.
$empty = DeviceMonitor::parseNeighbours('', '');
check('no output is not an error', $empty === ['macs' => [], 'ips' => []]);
$garbage = DeviceMonitor::parseNeighbours('not json at all', "\n\n");
check('unparseable output is not an error', $garbage === ['macs' => [], 'ips' => []]);

check('a MAC with too few octets is rejected', DeviceMonitor::normaliseMac('aa:bb:cc:dd:ee') === '');
check('a non-hex octet is rejected', DeviceMonitor::normaliseMac('aa:bb:cc:dd:ee:zz') === '');
check('an empty value is rejected', DeviceMonitor::normaliseMac('') === '');
check('a padded MAC is returned unchanged',
    DeviceMonitor::normaliseMac('00:11:22:33:44:55') === '00:11:22:33:44:55');

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
