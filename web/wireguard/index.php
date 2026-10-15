<?php
require dirname(dirname(__FILE__)) . '/access.php';
date_default_timezone_set('UTC');
function formatBytes($bytes)
{
    $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return ($unit === 0 ? number_format($size, 0) : number_format($size, 2)) . ' ' . $units[$unit];
}

function elapsedLabel($seconds)
{
    if ($seconds < 60) {
        return $seconds . 's ago';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ago';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm ago';
    }
    return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h ago';
}

function peerName($allowedIps, $names)
{
    $first = count($allowedIps) ? preg_replace('/\/.*/', '', $allowedIps[0]) : '';
    if ($first !== '' && isset($names[$first]) && trim($names[$first]) !== '') {
        return trim($names[$first]);
    }
    if ($first !== '') {
        return 'Client ' . $first;
    }
    return 'Unnamed client';
}

function readInterfaceInfo($name)
{
    $base = '/sys/class/net/' . $name;
    if (!is_dir($base)) {
        return array('name' => $name, 'exists' => false, 'up' => false);
    }
    $read = function ($path, $default) {
        $value = @file_get_contents($path);
        return $value === false ? $default : trim($value);
    };
    $flags = hexdec($read($base . '/flags', '0'));
    $carrierValue = $read($base . '/carrier', '');
    $receivedBytes = $read($base . '/statistics/rx_bytes', '0');
    $sentBytes = $read($base . '/statistics/tx_bytes', '0');
    return array(
        'name' => $name,
        'exists' => true,
        'up' => (bool) ($flags & 1),
        'carrier' => $carrierValue === '' ? null : $carrierValue === '1',
        'operstate' => $read($base . '/operstate', 'unknown'),
        'mtu' => (int) $read($base . '/mtu', '0'),
        'received_bytes' => (float) $receivedBytes,
        'sent_bytes' => (float) $sentBytes,
        'received' => formatBytes($receivedBytes),
        'sent' => formatBytes($sentBytes),
        'rx_errors' => (int) $read($base . '/statistics/rx_errors', '0'),
        'tx_errors' => (int) $read($base . '/statistics/tx_errors', '0'),
        'rx_dropped' => (int) $read($base . '/statistics/rx_dropped', '0'),
        'tx_dropped' => (int) $read($base . '/statistics/tx_dropped', '0')
    );
}

$names = array();
$namesFile = dirname(__FILE__) . '/clients.json';
if (is_readable($namesFile)) {
    $decodedNames = json_decode(file_get_contents($namesFile), true);
    if (is_array($decodedNames)) {
        $names = $decodedNames;
    }
}

$output = array();
$returnCode = 0;
exec('/usr/bin/sudo -n /config/scripts/usg-wg-dump 2>&1', $output, $returnCode);

$error = '';
$interfaces = array();
$peers = array();
$now = time();

if ($returnCode !== 0) {
    $error = 'WireGuard status is temporarily unavailable.';
} else {
    foreach ($output as $line) {
        $fields = explode("\t", trim($line));
        if (count($fields) === 5) {
            $interfaces[$fields[0]] = array(
                'name' => $fields[0],
                'listen_port' => (int) $fields[3]
            );
            continue;
        }
        if (count($fields) < 9) {
            continue;
        }

        $allowedIps = $fields[4] === '(none)' ? array() : explode(',', $fields[4]);
        $handshake = (int) $fields[5];
        $age = $handshake > 0 ? max(0, $now - $handshake) : null;
        $status = 'never';
        if ($age !== null && $age <= 180) {
            $status = 'online';
        } elseif ($age !== null && $age <= 900) {
            $status = 'recent';
        } elseif ($age !== null) {
            $status = 'offline';
        }

        $endpoint = $fields[3] === '(none)' ? '' : $fields[3];
        $peers[] = array(
            'name' => peerName($allowedIps, $names),
            'interface' => $fields[0],
            'tunnel_ips' => $allowedIps,
            'endpoint' => $endpoint,
            'status' => $status,
            'last_handshake' => $handshake,
            'last_seen' => $handshake > 0 ? date('Y-m-d H:i:s T', $handshake) : 'Never',
            'last_seen_relative' => $age === null ? 'Never' : elapsedLabel($age),
            'received_bytes' => (float) $fields[6],
            'sent_bytes' => (float) $fields[7],
            'received' => formatBytes($fields[6]),
            'sent' => formatBytes($fields[7])
        );
    }
}

usort($peers, function ($a, $b) {
    $rank = array('online' => 0, 'recent' => 1, 'offline' => 2, 'never' => 3);
    if ($rank[$a['status']] !== $rank[$b['status']]) {
        return $rank[$a['status']] - $rank[$b['status']];
    }
    return $b['last_handshake'] - $a['last_handshake'];
});

$counts = array('online' => 0, 'recent' => 0, 'offline' => 0, 'never' => 0);
foreach ($peers as $peer) {
    $counts[$peer['status']]++;
}


$server = json_decode(file_get_contents('/config/usg-services/server-public.json'), true);
$server['runtime'] = readInterfaceInfo('wg0');
$server['wan'] = readInterfaceInfo('br0');
$storageLines = array(); $storageCode = 0;
exec('/usr/bin/sudo -n /config/scripts/usg-usb-status 2>/dev/null', $storageLines, $storageCode);
$storage = $storageCode === 0 ? json_decode(implode("\n", $storageLines), true) : null;
$payload = array('ok' => $error === '', 'error' => $error,
    'generated_at' => date('Y-m-d H:i:s T'), 'server' => $server,
    'counts' => $counts, 'interfaces' => array_values($interfaces), 'peers' => $peers, 'storage' => $storage);
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    usgJson($payload, $error === '' ? 200 : 503);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>USG Services · WireGuard</title>
    <style>
        :root { color-scheme: dark; --bg:#09111f; --panel:#111c2e; --line:#26354d; --text:#e8eef8; --muted:#92a2b9; --green:#42d392; --amber:#f4bf50; --red:#fb7185; --grey:#718096; }
        * { box-sizing:border-box; }
        body { margin:0; background:radial-gradient(circle at top right,#142949 0,#09111f 42%); color:var(--text); font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; min-height:100vh; }
        main { width:min(1180px,calc(100% - 32px)); margin:0 auto; padding:42px 0 60px; }
        header { display:flex; justify-content:space-between; gap:24px; align-items:flex-end; margin-bottom:24px; }
        h1 { margin:0; font-size:clamp(28px,5vw,44px); letter-spacing:-.04em; }
        .subtitle,.muted { color:var(--muted); }
        .subtitle { margin:7px 0 0; }
        #refreshed { text-align:right; font-size:13px; }
        .summary { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
        .metric,.table-wrap,.teleport,.fabric { background:rgba(17,28,46,.86); border:1px solid var(--line); box-shadow:0 18px 50px rgba(0,0,0,.18); }
        .metric { padding:18px; border-radius:15px; }
        .metric strong { display:block; font-size:30px; line-height:1; margin-top:6px; }
        .metric span { color:var(--muted); font-size:13px; }
        .teleport { border-radius:17px; padding:20px; margin-bottom:20px; }
        .teleport-head { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:18px; }
        .teleport h2 { margin:0; font-size:20px; }
        .health { padding:6px 10px; border-radius:999px; background:rgba(66,211,146,.11); border:1px solid rgba(66,211,146,.28); color:var(--green); font-size:12px; font-weight:700; white-space:nowrap; }
        .health.offline { color:var(--red); background:rgba(251,113,133,.1); border-color:rgba(251,113,133,.3); }
        .open-gui { color:var(--text); text-decoration:none; padding:7px 11px; border-radius:9px; border:1px solid var(--line); background:rgba(255,255,255,.04); font-size:12px; font-weight:700; white-space:nowrap; }
        .open-gui:hover { border-color:#5273a3; background:rgba(255,255,255,.07); }
        .path { display:grid; grid-template-columns:1fr auto 1fr auto 1fr auto 1.15fr; align-items:center; gap:9px; }
        .path-node { min-height:84px; border:1px solid var(--line); border-radius:12px; padding:13px; background:rgba(255,255,255,.025); }
        .path-node b { display:block; margin-bottom:3px; }
        .path-node span { color:var(--muted); font-size:12px; }
        .arrow { color:#5d7599; font-size:19px; }
        .bridge-details { display:grid; grid-template-columns:repeat(5,1fr); gap:1px; margin-top:16px; overflow:hidden; border:1px solid var(--line); border-radius:12px; background:var(--line); }
        .detail { padding:12px; background:var(--panel); min-width:0; overflow-wrap:anywhere; }
        .detail span { display:block; color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
        .detail b { font-size:13px; font-weight:650; }
        .detail.warn b { color:var(--red); }
        .fabric { border-radius:17px; padding:20px; margin-bottom:20px; }
        .fabric-head { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:16px; }
        .fabric-head h2 { margin:0; font-size:22px; }
        .fabric-counts { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:7px; }
        .count-chip,.service-state,.vlan-badge { display:inline-flex; align-items:center; border-radius:999px; border:1px solid var(--line); padding:5px 9px; font-size:11px; font-weight:750; white-space:nowrap; }
        .count-chip { color:var(--muted); background:rgba(255,255,255,.025); }
        .service-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .service-card { border:1px solid var(--line); border-radius:14px; padding:15px; background:rgba(255,255,255,.022); min-width:0; }
        .service-card.active { border-color:rgba(66,211,146,.42); }
        .service-card.degraded { border-color:rgba(244,191,80,.5); }
        .service-card.offline,.service-card.missing { border-color:rgba(251,113,133,.42); }
        .service-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:13px; }
        .service-title { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
        .service-title h3 { margin:0; font-size:18px; }
        .vlan-badge { color:#b8c9e3; background:rgba(93,117,153,.15); }
        .service-state.active { color:var(--green); border-color:rgba(66,211,146,.35); background:rgba(66,211,146,.1); }
        .service-state.ready { color:#8fb8f2; border-color:rgba(110,168,247,.35); background:rgba(110,168,247,.1); }
        .service-state.degraded { color:var(--amber); border-color:rgba(244,191,80,.4); background:rgba(244,191,80,.1); }
        .service-state.offline,.service-state.missing { color:var(--red); border-color:rgba(251,113,133,.35); background:rgba(251,113,133,.09); }
        .service-facts { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; background:var(--line); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
        .service-fact { background:var(--panel); padding:9px; min-width:0; }
        .service-fact span { display:block; color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.06em; }
        .service-fact b { display:block; margin-top:3px; font-size:12px; overflow-wrap:anywhere; }
        .tunnel-list { display:grid; gap:8px; margin-top:11px; }
        .tunnel { display:grid; grid-template-columns:minmax(100px,.8fr) minmax(145px,1.2fr) auto; gap:10px; align-items:center; padding:10px; border:1px solid var(--line); border-radius:10px; background:rgba(0,0,0,.09); }
        .tunnel strong,.tunnel span { min-width:0; overflow-wrap:anywhere; }
        .tunnel-meta { color:var(--muted); font-size:11px; }
        .empty-service { margin-top:11px; color:var(--muted); font-size:12px; border:1px dashed var(--line); border-radius:10px; padding:10px; }
        .section-label { margin:24px 0 10px; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
        .table-wrap { border-radius:17px; overflow:hidden; }
        table { width:100%; border-collapse:collapse; }
        th { padding:13px 16px; text-align:left; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; font-size:11px; background:rgba(255,255,255,.02); }
        td { padding:16px; border-top:1px solid var(--line); vertical-align:middle; }
        .client { font-weight:700; }
        .small { display:block; color:var(--muted); font-size:12px; margin-top:2px; }
        .status { display:inline-flex; align-items:center; gap:7px; font-weight:650; text-transform:capitalize; }
        .dot { width:9px; height:9px; border-radius:50%; background:var(--grey); box-shadow:0 0 0 4px rgba(113,128,150,.13); }
        .online .dot { background:var(--green); box-shadow:0 0 0 4px rgba(66,211,146,.13); }
        .recent .dot { background:var(--amber); box-shadow:0 0 0 4px rgba(244,191,80,.13); }
        .offline .dot { background:var(--red); box-shadow:0 0 0 4px rgba(251,113,133,.13); }
        .error { margin-bottom:20px; padding:14px 16px; border:1px solid rgba(251,113,133,.45); background:rgba(251,113,133,.1); border-radius:12px; }
        footer { color:var(--muted); font-size:12px; margin-top:14px; }
        @media (max-width:900px) { .service-grid{grid-template-columns:1fr;} }
        @media (max-width:800px) { .summary { grid-template-columns:repeat(2,1fr); } header,.fabric-head { align-items:flex-start; flex-direction:column; } #refreshed{text-align:left;} .fabric-counts{justify-content:flex-start;} .service-facts{grid-template-columns:repeat(2,1fr);} .tunnel{grid-template-columns:1fr;} .path{grid-template-columns:1fr;} .arrow{text-align:center;transform:rotate(90deg);} .bridge-details{grid-template-columns:repeat(2,1fr);} thead{display:none;} tr{display:block;padding:10px 0;border-top:1px solid var(--line);} tbody tr:first-child{border-top:0;} td{display:flex;justify-content:space-between;gap:20px;border:0;padding:7px 14px;text-align:right;} td:before{content:attr(data-label);color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em;text-align:left;} td:first-child{padding-top:12px;} td:last-child{padding-bottom:12px;} }

.connection-details{margin-top:14px;color:#cbd5e1}
.connection-details>summary{cursor:pointer;padding:8px 0;font-size:14px}
.connection-details[open]>summary{margin-bottom:10px}
.public-key{margin-top:12px;color:#cbd5e1;font-size:13px}
.public-key summary{cursor:pointer}
.public-key code{display:block;margin-top:8px;overflow-wrap:anywhere;user-select:all}
.storage-capacity{margin:16px 0 20px;padding:18px;border:1px solid #334155;border-radius:12px}
.storage-capacity .service-facts{margin:12px 0;grid-template-columns:repeat(3,minmax(0,1fr))}
@media(max-width:520px){.storage-capacity .service-facts{grid-template-columns:1fr}}
.storage-meter{display:block;width:100%;height:12px;margin:12px 0 8px;border:0;border-radius:99px;overflow:hidden;background:#263244;accent-color:#38bdf8}
.storage-meter::-webkit-progress-bar{background:#263244;border-radius:99px}
.storage-meter::-webkit-progress-value{background:#38bdf8;border-radius:99px}
.storage-meter::-moz-progress-bar{background:#38bdf8;border-radius:99px}
    </style>
</head><body><main>
<header><div><h1>USG Services</h1><p class="subtitle">WireGuard VPN and USB storage</p></div><div id="refreshed"></div></header>
<div id="error" class="error" style="display:none"></div>
<section class="summary">
<div class="metric"><span>Online</span><strong id="count-online">0</strong></div>
<div class="metric"><span>Recent</span><strong id="count-recent">0</strong></div>
<div class="metric"><span>Offline</span><strong id="count-offline">0</strong></div>
<div class="metric"><span>Never seen</span><strong id="count-never">0</strong></div>
</section>
<section class="fabric"><div class="fabric-head"><div><h2>VPN server</h2><span class="small">LAN bridge with routed VPN access</span></div><span id="vpn-state" class="service-state"></span></div><details class="connection-details"><summary>Show VPN connection details</summary><div class="service-facts" id="server"></div><details class="public-key"><summary>Show public key</summary><code id="server-public-key"></code></details><div class="empty-service" id="deployment"></div></details></section>
<section class="fabric"><div class="fabric-head"><div><h2>USB storage</h2><span class="small">Live HDD capacity and usage</span></div></div><div id="storage-capacity" aria-live="polite"></div><details class="connection-details"><summary>Show storage connection details</summary><div id="storage"></div><p class="small">USB files mounts automatically by UUID. Connect by SFTP on port 22 with your USG login; folder /srv/usg-files. SMB is not configured.</p></details></section>
<details class="connection-details"><summary>Show client connection details</summary><div class="table-wrap"><table><thead><tr><th>Client</th><th>Status</th><th>Last handshake</th><th>Endpoint</th><th>Traffic</th></tr></thead><tbody id="peers"></tbody></table></div></details>
<section class="fabric" style="margin-top:20px"><h2>Setup API</h2><details class="connection-details"><summary>Show setup API details</summary><p class="subtitle">Available from this gateway’s LAN and VPN only.</p><div class="service-facts"><div class="service-fact"><span>Provision client</span><b>POST /add_wireguard_peer.php</b></div><div class="service-fact"><span>Authentication</span><b>X-API-Key header</b></div><div class="service-fact"><span>Server information</span><b>/server-info.php</b></div><div class="service-fact"><span>Compatibility</span><b>Existing client JSON format</b></div></div></details></section>
<footer>Online: handshake within 3 minutes. Recent: within 15 minutes. A configured interface alone does not prove remote or internet connectivity.</footer>
</main><script>
(function(){
function esc(x){var e=document.createElement('span');e.textContent=x==null?'':String(x);return e.innerHTML;}
function fact(label,value){return '<div class="service-fact"><span>'+esc(label)+'</span><b>'+esc(value)+'</b></div>';}
function diskBytes(value){
if(typeof value!=='number'||!isFinite(value)||value<0)return 'Unavailable';
var units=['B','KB','MB','GB','TB'],i=0;
while(value>=1000&&i<units.length-1){value/=1000;i++;}
return value.toFixed(i?2:0)+' '+units[i];
}
function diskCapacity(storage){
if(!storage)return '<p class="muted">HDD space information is unavailable.</p>';
if(!storage.mounted)return '<p class="muted">USB files is not mounted. HDD space information is unavailable.</p>';
if(typeof storage.total_bytes!=='number'||storage.total_bytes<=0||typeof storage.used_bytes!=='number'||typeof storage.available_bytes!=='number')return '<p class="muted">Unable to read HDD space information.</p>';
var pct=Math.max(0,Math.min(100,100*storage.used_bytes/storage.total_bytes));
var label=pct>0&&pct<0.1?'<0.1':pct.toFixed(1);
return '<div class="storage-capacity"><strong>USB files</strong><div class="service-facts">'+fact('Total capacity',diskBytes(storage.total_bytes))+fact('Used',diskBytes(storage.used_bytes))+fact('Free space',diskBytes(storage.available_bytes))+'</div><progress class="storage-meter" max="100" value="'+pct+'" aria-label="HDD space used">'+esc(label)+'%</progress><span class="small">'+esc(label)+'% used · Refreshes every 15 seconds</span></div>';
}
function render(d){
['online','recent','offline','never'].forEach(function(k){document.getElementById('count-'+k).textContent=d.counts[k]||0;});
document.getElementById('refreshed').innerHTML='Updated '+esc(d.generated_at)+'<br><span class="muted">Refreshes every 15 seconds</span>';
var s=d.server, live=s.runtime&&s.runtime.up, error=document.getElementById('error');
error.textContent=d.error||'';error.style.display=d.error?'block':'none';
var badge=document.getElementById('vpn-state');badge.textContent=live?'Listening':'Unavailable';badge.className='service-state '+(live?'ready':'degraded');
document.getElementById('server').innerHTML=[['VPN address',s.address],['Client subnet',s.client_subnet],['UDP port',s.listen_port],['Public endpoint',s.endpoint||'Not set'],['LAN interface','br0 · DHCP'],['VPN routing','Upstream route required; no NAT'],['Client DNS',s.dns],['MTU',s.mtu]].map(function(x){return fact(x[0],x[1]);}).join('');
document.getElementById('deployment').textContent=s.endpoint?'Public endpoint configured; remote reachability must be tested at the destination.':'Public endpoint is not set. Forward the configured WireGuard UDP port on the upstream router and add a return route for the configured VPN subnet through this server. This box performs no NAT.';
document.getElementById('storage-capacity').innerHTML=diskCapacity(d.storage);
document.getElementById('server-public-key').textContent=s.public_key||'Unavailable';
var usb=d.storage&&d.storage.devices;
if(usb)usb=usb.filter(function(x){return x.bytes>0;});
document.getElementById('storage').innerHTML=usb?(usb.length?usb.map(function(x){return '<article class="service-card"><h3>'+esc(x.model)+' · '+esc((x.bytes/1e9).toFixed(1))+' GB</h3>'+x.partitions.map(function(p){return '<div class="small">'+esc(p.device)+' · '+esc(p.label||'Unlabelled')+' · '+esc(p.filesystem)+' · '+(p.mounted?'Mounted at '+esc((p.mountpoints||[]).join(', ')):'Not mounted')+'</div>';}).join('')+'</article>';}).join(''):'<p class="muted">No USB storage detected.</p>'):'<p class="muted">Storage status unavailable.</p>';
document.getElementById('peers').innerHTML=d.peers.length?d.peers.map(function(p){return '<tr><td data-label="Client"><span class="client">'+esc(p.name)+'</span><span class="small">'+esc(p.tunnel_ips.join(', '))+'</span></td><td data-label="Status"><span class="status '+esc(p.status)+'"><i class="dot"></i>'+esc(p.status)+'</span></td><td data-label="Last handshake">'+esc(p.last_seen_relative)+'</td><td data-label="Endpoint">'+esc(p.endpoint||'Not observed')+'</td><td data-label="Traffic">↓ '+esc(p.received)+'<br>↑ '+esc(p.sent)+'</td></tr>';}).join(''):'<tr><td colspan="5">No clients provisioned yet.</td></tr>';
}
function refresh(){var x=new XMLHttpRequest();x.open('GET','?format=json',true);x.timeout=8000;x.onreadystatechange=function(){if(x.readyState===4){if(x.status===200){try{render(JSON.parse(x.responseText));}catch(e){fail();}}else{fail();}}};x.onerror=fail;x.ontimeout=fail;x.send();}
function fail(){var e=document.getElementById('error');e.style.display='block';e.textContent='Live refresh failed. The information shown may be out of date.';}
render(<?php echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);window.setInterval(refresh,15000);
}());</script></body></html>
