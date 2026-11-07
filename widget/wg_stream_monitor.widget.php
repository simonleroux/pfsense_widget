<?php
/*
 * WireGuard Stream Monitor - pfSense dashboard widget.
 *
 * Shows which remote WireGuard peers are currently passing a meaningful amount
 * of traffic. The data comes from scripts/wg-stream-check.sh.
 *
 * Install: copy to /usr/local/www/widgets/widgets/wg_stream_monitor.widget.php
 * VERSION="1.0.0"
 */
require_once("guiconfig.inc");

// ---- Settings (edit here) --------------------------------------------------
$checker        = "/usr/local/bin/wg-stream-check.sh";
$sample_seconds = 3;     // time between the two counter samples
$threshold_kbps = 100;   // rx+tx rate at/above which a peer is "streaming"
$stale_seconds  = 180;   // handshake older than this = offline
// Optional watch list: only show these peers. Match by public key prefix or by
// an allowed-IP entry, e.g. array("10.10.0.23/32", "AbC123xyz..."). Empty = show all.
$watch_peers    = array();
// ----------------------------------------------------------------------------

$wkey = isset($widgetkey) ? $widgetkey : "wg_stream_monitor";

function wg_status_badge($status) {
    $map = array(
        "streaming"       => array("success", "Streaming"),
        "connected-idle"  => array("info",    "Connected (idle)"),
        "offline"         => array("default", "Offline"),
        "never-connected" => array("default", "Never connected"),
    );
    $m = isset($map[$status]) ? $map[$status] : array("default", $status);
    return '<span class="label label-' . $m[0] . '">' . htmlspecialchars($m[1]) . '</span>';
}

function wg_is_watched($peer, $watch_peers) {
    if (count($watch_peers) === 0) {
        return true;
    }
    foreach ($watch_peers as $w) {
        if (strpos($peer['peer'], $w) === 0 || strpos($peer['allowed_ips'], $w) !== false) {
            return true;
        }
    }
    return false;
}

function wg_fetch_peers($checker, $sample, $threshold, $stale) {
    // Every argument is a fixed value from the settings above, cast to int.
    $cmd = escapeshellcmd($checker) . ' ' . intval($sample) . ' ' . intval($threshold) . ' ' . intval($stale) . ' 2>/dev/null';
    $peers = json_decode((string)shell_exec($cmd), true);
    return is_array($peers) ? $peers : null;
}

// Map WireGuard interface names (e.g. tun_wg0) to the tunnel descriptions set in
// pfSense (VPN > WireGuard > Tunnels). Falls back to the interface name when unset.
function wg_tunnel_names() {
    global $config;
    $names = array();
    $items = isset($config['wireguard']['tunnels']['item']) ? $config['wireguard']['tunnels']['item'] : array();
    if (!is_array($items)) {
        return $names;
    }
    if (isset($items['name'])) {
        $items = array($items); // a single tunnel is stored without a list wrapper
    }
    foreach ($items as $t) {
        if (!empty($t['name']) && !empty($t['descr'])) {
            $names[$t['name']] = $t['descr'];
        }
    }
    return $names;
}

function wg_render_table($peers, $watch_peers) {
    if ($peers === null) {
        return '<p class="text-danger">Could not read WireGuard state.</p>';
    }
    if (isset($peers['error'])) {
        return '<p class="text-danger">' . htmlspecialchars($peers['error']) . '</p>';
    }

    $rows = array_values(array_filter($peers, function ($p) use ($watch_peers) {
        return wg_is_watched($p, $watch_peers);
    }));
    if (count($rows) === 0) {
        return '<p>No matching WireGuard peers.</p>';
    }

    $names = wg_tunnel_names();
    $html ='<table class="table table-striped table-condensed"><thead><tr>'
          . '<th>Tunnel</th><th>Endpoint</th><th>Rate</th><th>Status</th>'
          . '</tr></thead><tbody>';
    foreach ($rows as $p) {
        $label = isset($names[$p['interface']]) ? $names[$p['interface']] : $p['interface'];
        $html .= '<tr>'
              . '<td title="' . htmlspecialchars($p['peer']) . '">' . htmlspecialchars($label) . '</td>'
              . '<td>' . htmlspecialchars($p['endpoint']) . '</td>'
              . '<td>' . number_format($p['kbps'], 1) . ' kbps</td>'
              . '<td>' . wg_status_badge($p['status']) . '</td>'
              . '</tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

// ---- AJAX endpoint: the page script polls this URL --------------------------
if (isset($_REQUEST['ajax'])) {
    echo wg_render_table(
        wg_fetch_peers($checker, $sample_seconds, $threshold_kbps, $stale_seconds),
        $watch_peers
    );
    exit;
}
// ----------------------------------------------------------------------------
?>
<div class="panel-body" id="<?=htmlspecialchars($wkey)?>-body">
  <p>Sampling...</p>
</div>
<script>
(function () {
  var body = document.getElementById("<?=htmlspecialchars($wkey)?>-body");
  var url = "widgets/widgets/wg_stream_monitor.widget.php?ajax=1";

  function refresh() {
    fetch(url, { credentials: "same-origin" })
      .then(function (r) { return r.text(); })
      .then(function (html) { body.innerHTML = html; })
      .catch(function () { body.innerHTML = '<p class="text-danger">Refresh failed.</p>'; });
  }

  refresh();
  setInterval(refresh, 10000);
})();
</script>
