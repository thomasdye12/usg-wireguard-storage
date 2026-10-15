<?php
require dirname(__FILE__) . '/access.php';
// Provision one client using a locally generated API token.
$expected = trim(@file_get_contents('/config/usg-services/api.token'));
$provided = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] :
    '';
$valid = is_string($provided) && strlen($expected) === 64 && strlen($provided) === 64;
$diff = 0;
if ($valid) { for ($i = 0; $i < 64; $i++) { $diff |= ord($expected[$i]) ^ ord($provided[$i]); } }
if (!$valid || $diff !== 0) { usgJson(array('error' => 'Invalid API key'), 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    usgJson(array('error' => 'Method not allowed'), 405);
}
$output = array(); $code = 0;
exec('/usr/bin/sudo -n -u ubnt /bin/vbash /config/scripts/gen-usg-wg-client 2>/dev/null', $output, $code);
$data = json_decode(implode("\n", $output), true);
if ($code !== 0 || !is_array($data) || empty($data['client']['PrivateKey'])) {
    usgJson(array('error' => 'Peer provisioning failed; check configuration before retrying.'), 500);
}
usgJson($data, 200);
