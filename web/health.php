<?php
require dirname(__FILE__) . '/access.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    header('Allow: GET, HEAD');
    usgJson(array('status' => 'error', 'reason' => 'method_not_allowed'), 405);
}
$lines = array(); $code = 0;
exec('/usr/bin/sudo -n /config/scripts/usg-storage-health 2>/dev/null', $lines, $code);
$health = json_decode(implode("\n", $lines), true);
if (!is_array($health) || !isset($health['status'])) {
    $health = array('status' => 'error', 'reason' => 'health_probe_unavailable');
}
$ok = $code === 0 && $health['status'] === 'ok';
if (!$ok) $health['status'] = 'error';
usgJson($health, $ok ? 200 : 503);
