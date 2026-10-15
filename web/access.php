<?php
function usgTrustedClient() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($ip === '127.0.0.1' || $ip === '::1') return true;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $lines = array();
    exec('/sbin/ip -o -4 addr show 2>/dev/null', $lines);
    foreach ($lines as $line) {
        if (!preg_match('/^\d+: (br0|wg0)\s+inet ([0-9.]+)\/(\d+) /', $line, $m)) continue;
        $bits = (int)$m[3];
        if ($bits < 1 || $bits > 32) continue;
        $mask = -1 << (32 - $bits);
        if ((ip2long($ip) & $mask) === (ip2long($m[2]) & $mask)) return true;
    }
    return false;
}
function usgJson($body, $status) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body);
    exit;
}
if (!usgTrustedClient()) {
    usgJson(array('error' => 'Available only from this gateway LAN or VPN.'), 403);
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
