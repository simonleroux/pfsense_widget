<?php
/*
 * WireGuard Streaming - pfSense dashboard widget.
 *
 * Shows which remote WireGuard peers are currently passing a meaningful amount
 * of traffic. The data comes from scripts/wg-stream-check.sh.
 * Settings are changed with the wrench icon on the widget and saved in pfSense.
 *
 * Install: copy to /usr/local/www/widgets/widgets/wg_stream_monitor.widget.php
 * VERSION="1.1.0"
 */
require_once("guiconfig.inc");

// ---- Fixed settings ----------------------------------------------------------
$checker    = "/usr/local/bin/wg-stream-check.sh";
// Remembers which peers were streaming between checks (for the end-of-stream failsafe).
$state_file = "/var/run/wg_stream_monitor.state";

// Defaults, used until you save different values with the wrench icon.
$wg_defaults = array(
    'sample_seconds'   => 3,    // time between the two counter samples (1-30)
    'threshold_kbps'   => 100,  // rx+tx rate at/above which a peer is "streaming" (1-1000000)
    'stale_seconds'    => 180,  // handshake older than this = offline (10-3600)
    'end_after_checks' => 3,    // checks below the threshold before streaming is declared over (1-10)
    'watch_peers'      => '',   // optional: comma-separated public key prefixes or allowed IPs
);
// ----------------------------------------------------------------------------

if (!isset($user_settings) || !is_array($user_settings)) {
    $user_settings = array();
}

function wg_valid_key($key) {
    return is_string($key) && preg_match('/^wg_stream_monitor-\d+$/', $key) === 1;
}

// Dashboard key for this instance (e.g. wg_stream_monitor-0)
$wkey = "wg_stream_monitor-0";
if (isset($widgetkey) && wg_valid_key($widgetkey)) {
    $wkey = $widgetkey;
} elseif (isset($_REQUEST['widgetkey']) && wg_valid_key($_REQUEST['widgetkey'])) {
    $wkey = $_REQUEST['widgetkey'];
}

function wg_int($value, $min, $max, $default) {
    $n = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => $min, 'max_range' => $max)));
    return ($n === false) ? $default : $n;
}

// Turns raw saved or posted values into safe settings, using defaults for anything missing or invalid
function wg_clean_settings($raw, $defaults) {
    $raw = is_array($raw) ? $raw : array();
    $watch = isset($raw['watch_peers']) ? (string)$raw['watch_peers'] : '';
    return array(
        'sample_seconds'   => wg_int(isset($raw['sample_seconds']) ? $raw['sample_seconds'] : null, 1, 30, $defaults['sample_seconds']),
        'threshold_kbps'   => wg_int(isset($raw['threshold_kbps']) ? $raw['threshold_kbps'] : null, 1, 1000000, $defaults['threshold_kbps']),
        'stale_seconds'    => wg_int(isset($raw['stale_seconds']) ? $raw['stale_seconds'] : null, 10, 3600, $defaults['stale_seconds']),
        'end_after_checks' => wg_int(isset($raw['end_after_checks']) ? $raw['end_after_checks'] : null, 1, 10, $defaults['end_after_checks']),
        'watch_peers'      => substr(preg_replace('/[^A-Za-z0-9+\/=_.:, -]/', '', $watch), 0, 500),
    );
}

$settings = wg_clean_settings(isset($user_settings['widgets'][$wkey]) ? $user_settings['widgets'][$wkey] : array(), $wg_defaults);

// ---- Save settings (form in the wrench panel posts back to this file) --------
if (isset($_POST['widgetkey']) && wg_valid_key($_POST['widgetkey'])) {
    $key = $_POST['widgetkey'];
    $existing = isset($user_settings['widgets'][$key]) && is_array($user_settings['widgets'][$key]) ? $user_settings['widgets'][$key] : array();
    $user_settings['widgets'][$key] = array_merge($existing, wg_clean_settings($_POST, $wg_defaults));
    save_widget_settings($_SESSION['Username'], $user_settings['widgets'], gettext("Saved WireGuard Streaming widget settings via Dashboard."));
    header("Location: /");
    exit;
}

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

// $watch is a list of non-empty strings. Empty list = show all peers.
function wg_is_watched($peer, $watch) {
    if (count($watch) === 0) {
        return true;
    }
    foreach ($watch as $w) {
        if (strpos($peer['peer'], $w) === 0 || strpos($peer['allowed_ips'], $w) !== false) {
            return true;
        }
    }
    return false;
}

function wg_fetch_peers($checker, $s) {
    // Every argument is a setting cast to int, so nothing user-typed reaches the shell.
    $cmd = escapeshellcmd($checker) . ' ' . intval($s['sample_seconds']) . ' ' . intval($s['threshold_kbps']) . ' ' . intval($s['stale_seconds']) . ' 2>/dev/null';
    $peers = json_decode((string)shell_exec($cmd), true);
    return is_array($peers) ? $peers : null;
}

// Failsafe: a peer that was streaming stays "streaming" until it has been below the
// threshold (not streaming) for end_after_checks checks in a row. The state is kept in a
// file between polls. Each poll is one check.
function wg_apply_streaming_memory($peers, $end_after, $state_file) {
    $old = json_decode((string)@file_get_contents($state_file), true);
    if (!is_array($old)) {
        $old = array();
    }
    $new = array();
    foreach ($peers as $i => $p) {
        if (!isset($p['status'], $p['interface'], $p['peer'])) {
            continue;
        }
        $key = $p['interface'] . '|' . $p['peer'];
        $was = isset($old[$key]) && is_array($old[$key]) ? $old[$key] : array('streaming' => false, 'quiet' => 0);

        if ($p['status'] === 'streaming') {
            $now = array('streaming' => true, 'quiet' => 0);
        } elseif (!empty($was['streaming'])) {
            $quiet = (int)$was['quiet'] + 1;
            $now = ($quiet >= $end_after)
                ? array('streaming' => false, 'quiet' => 0)
                : array('streaming' => true, 'quiet' => $quiet);
        } else {
            $now = array('streaming' => false, 'quiet' => 0);
        }
        $new[$key] = $now;

        if ($now['streaming'] && $p['status'] !== 'streaming') {
            $peers[$i]['status'] = 'streaming';
            $peers[$i]['quiet'] = $now['quiet'];
        }
    }
    @file_put_contents($state_file, json_encode($new), LOCK_EX);
    return $peers;
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

function wg_render_table($peers, $s) {
    if ($peers === null) {
        return '<p class="text-danger">Could not read WireGuard state.</p>';
    }
    if (isset($peers['error'])) {
        return '<p class="text-danger">' . htmlspecialchars($peers['error']) . '</p>';
    }

    $watch = array_values(array_filter(array_map('trim', explode(',', $s['watch_peers'])), 'strlen'));
    $rows = array_values(array_filter($peers, function ($p) use ($watch) {
        return wg_is_watched($p, $watch);
    }));
    if (count($rows) === 0) {
        return '<p>No matching WireGuard peers.</p>';
    }

    $names = wg_tunnel_names();
    $html = '<table class="table table-striped table-condensed"><thead><tr>'
          . '<th>Tunnel</th><th>Endpoint</th><th>Rate</th><th>Status</th>'
          . '</tr></thead><tbody>';
    foreach ($rows as $p) {
        $label = isset($names[$p['interface']]) ? $names[$p['interface']] : $p['interface'];
        $status = wg_status_badge($p['status']);
        if (!empty($p['quiet'])) {
            $status .= ' <small class="text-muted">quiet ' . (int)$p['quiet'] . ' of ' . (int)$s['end_after_checks'] . '</small>';
        }
        $html .= '<tr>'
              . '<td title="' . htmlspecialchars($p['peer']) . '">' . htmlspecialchars($label) . '</td>'
              . '<td>' . htmlspecialchars($p['endpoint']) . '</td>'
              . '<td>' . number_format($p['kbps'], 1) . ' kbps</td>'
              . '<td>' . $status . '</td>'
              . '</tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function wg_settings_form($wkey, $s) {
    $e = function ($v) { return htmlspecialchars((string)$v); };
    $field = function ($name, $label, $value, $min, $max, $help) use ($e) {
        return '<div class="form-group">'
            . '<label class="col-sm-4 control-label" for="wg-' . $name . '">' . $e($label) . '</label>'
            . '<div class="col-sm-6"><input type="number" class="form-control" id="wg-' . $name . '" name="' . $name . '"'
            . ' value="' . $e($value) . '" min="' . $min . '" max="' . $max . '" required>'
            . '<span class="help-block">' . $e($help) . '</span></div></div>';
    };
    return '<form action="/widgets/widgets/wg_stream_monitor.widget.php" method="post" class="form-horizontal">'
        . '<input type="hidden" name="widgetkey" value="' . $e($wkey) . '">'
        . '<div class="panel-body">'
        . $field('threshold_kbps', 'Streaming threshold (kbps)', $s['threshold_kbps'], 1, 1000000,
                 'Combined rx+tx rate at or above this counts as streaming.')
        . $field('end_after_checks', 'Checks before streaming ends', $s['end_after_checks'], 1, 10,
                 'A streaming peer is shown as streaming until it is below the threshold this many checks in a row.')
        . $field('sample_seconds', 'Sample time (seconds)', $s['sample_seconds'], 1, 30,
                 'Time between the two counter samples. Each refresh blocks for this long.')
        . $field('stale_seconds', 'Offline after (seconds)', $s['stale_seconds'], 10, 3600,
                 'Peers with a last handshake older than this show as offline.')
        . '<div class="form-group">'
        . '<label class="col-sm-4 control-label" for="wg-watch_peers">Only show these peers</label>'
        . '<div class="col-sm-6"><input type="text" class="form-control" id="wg-watch_peers" name="watch_peers" value="' . $e($s['watch_peers']) . '">'
        . '<span class="help-block">Comma-separated public key prefixes or allowed IPs, e.g. 10.10.0.23/32. Empty = show all.</span></div></div>'
        . '<div class="form-group"><div class="col-sm-offset-4 col-sm-6">'
        . '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save icon-embed-btn"></i>' . $e(gettext('Save')) . '</button>'
        . '</div></div>'
        . '</div></form>';
}

// ---- AJAX endpoint: the page script polls this URL --------------------------
if (isset($_REQUEST['ajax'])) {
    $peers = wg_fetch_peers($checker, $settings);
    if (is_array($peers) && !isset($peers['error'])) {
        $peers = wg_apply_streaming_memory($peers, $settings['end_after_checks'], $state_file);
    }
    echo wg_render_table($peers, $settings);
    exit;
}
// ----------------------------------------------------------------------------
$footer_id = isset($widget_panel_footer_id) ? $widget_panel_footer_id : 'widget-' . $wkey . '_panel-footer';
?>
<div id="<?=htmlspecialchars($wkey)?>-body">
  <p>Sampling...</p>
</div>
<script>
(function () {
  var body = document.getElementById("<?=htmlspecialchars($wkey)?>-body");
  var url = "widgets/widgets/wg_stream_monitor.widget.php?ajax=1&widgetkey=<?=htmlspecialchars($wkey)?>";

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
</div><div id="<?=htmlspecialchars($footer_id)?>" class="panel-footer collapse">
<?=wg_settings_form($wkey, $settings)?>
</div>
