<?php
/*
 * wireguard_streaming_core.inc - shared code for the WireGuard Streaming and
 * WireGuard Peers widgets. Installed next to the widgets in /usr/local/www/widgets/widgets/
 * (not in widgets/include/, which pfSense loads as title files).
 * VERSION="1.5.5"
 */

define('WGS_SETTINGS_KEY', 'wireguard_streaming-0');   // both widgets share these settings
define('WGS_CHECKER', '/usr/local/bin/wg-stream-check.sh');
define('WGS_STATE_FILE', '/var/run/wireguard_streaming.state');
define('WGS_CACHE_FILE', '/var/run/wireguard_streaming.cache');
define('WGS_CACHE_SECONDS', 5);  // a second widget within this time reuses the last checker result

function wgs_defaults() {
    return array(
        'threshold_kbps' => 100,  // rx+tx rate at/above which a peer is "streaming" (1-1000000)
        'confirm_checks' => 3,    // checks in a row needed to declare streaming started or ended (1-10)
        'sample_seconds' => 3,    // time between the two counter samples (1-30)
        'stale_seconds'  => 180,  // handshake older than this = offline (10-3600)
        'watch_peers'    => '',   // optional: comma-separated public key prefixes or allowed IPs
    );
}

function wgs_valid_key($key, $base) {
    return is_string($key) && preg_match('/^' . preg_quote($base, '/') . '-\d+$/', $key) === 1;
}

function wgs_int($value, $min, $max, $default) {
    $n = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => $min, 'max_range' => $max)));
    return ($n === false) ? $default : $n;
}

// Turns saved or posted values into safe settings. Includes the stock refresh interval.
function wgs_clean_settings($raw, $wgg) {
    $d = wgs_defaults();
    $raw = is_array($raw) ? $raw : array();
    $watch = isset($raw['watch_peers']) ? (string)$raw['watch_peers'] : '';
    return array(
        'refresh_interval' => wgs_int(isset($raw['refresh_interval']) ? $raw['refresh_interval'] : null, 0, 10, 10),
        'threshold_kbps'   => wgs_int(isset($raw['threshold_kbps']) ? $raw['threshold_kbps'] : null, 1, 1000000, $d['threshold_kbps']),
        'confirm_checks'   => wgs_int(isset($raw['confirm_checks']) ? $raw['confirm_checks'] : null, 1, 10, $d['confirm_checks']),
        'sample_seconds'   => wgs_int(isset($raw['sample_seconds']) ? $raw['sample_seconds'] : null, 1, 30, $d['sample_seconds']),
        'stale_seconds'    => wgs_int(isset($raw['stale_seconds']) ? $raw['stale_seconds'] : null, 10, 3600, $d['stale_seconds']),
        'watch_peers'      => substr(preg_replace('/[^A-Za-z0-9+\/=_.:, -]/', '', $watch), 0, 500),
    );
}

// The shared settings, from the stored widget settings
function wgs_load_settings($user_settings, $wgg) {
    $stored = isset($user_settings['widgets'][WGS_SETTINGS_KEY]) ? $user_settings['widgets'][WGS_SETTINGS_KEY] : array();
    return wgs_clean_settings($stored, $wgg);
}

// Runs the checker and returns its peers, or null if it fails. Applies the watch list.
function wgs_checker_peers($s) {
    $cmd = escapeshellcmd(WGS_CHECKER) . ' ' . intval($s['sample_seconds']) . ' ' . intval($s['threshold_kbps']) . ' ' . intval($s['stale_seconds']) . ' 2>/dev/null';
    $peers = json_decode((string)shell_exec($cmd), true);
    if (!is_array($peers) || isset($peers['error'])) {
        return null;
    }
    $watch = array_values(array_filter(array_map('trim', explode(',', $s['watch_peers'])), 'strlen'));
    if (count($watch) === 0) {
        return $peers;
    }
    return array_values(array_filter($peers, function ($p) use ($watch) {
        foreach ($watch as $w) {
            if (strpos($p['peer'], $w) === 0 || strpos($p['allowed_ips'], $w) !== false) {
                return true;
            }
        }
        return false;
    }));
}

// Failsafe, applied in both directions. A peer changes its declared state only after the
// raw check disagrees with the declared state for $confirm checks in a row.
function wgs_apply_memory($peers, $confirm) {
    $old = json_decode((string)@file_get_contents(WGS_STATE_FILE), true);
    if (!is_array($old)) {
        $old = array();
    }
    $new = array();
    foreach ($peers as $i => $p) {
        if (!isset($p['status'], $p['interface'], $p['peer'])) {
            continue;
        }
        $key = $p['interface'] . '|' . $p['peer'];
        $was = isset($old[$key]) && is_array($old[$key]) ? $old[$key] : array();
        $declared = !empty($was['streaming']);
        $pending = isset($was['count']) ? (int)$was['count'] : 0;
        $raw = ($p['status'] === 'streaming');

        if ($raw === $declared) {
            $now = array('streaming' => $declared, 'count' => 0);
        } else {
            $pending++;
            $now = ($pending >= $confirm)
                ? array('streaming' => $raw, 'count' => 0)
                : array('streaming' => $declared, 'count' => $pending);
        }
        $new[$key] = $now;

        if ($now['streaming'] && !$raw) {
            // Still declared streaming, but this check is below the threshold
            $peers[$i]['status'] = 'streaming';
            $peers[$i]['pending'] = 'quiet';
            $peers[$i]['count'] = $now['count'];
        } elseif (!$now['streaming'] && $raw) {
            // Not declared streaming yet, but this check is above the threshold
            $peers[$i]['status'] = 'connected-idle';
            $peers[$i]['pending'] = 'active';
            $peers[$i]['count'] = $now['count'];
        }
    }
    @file_put_contents(WGS_STATE_FILE, json_encode($new), LOCK_EX);
    return $peers;
}

// One checker round, shared by both widgets. The failsafe counts a round once: a second
// widget that asks within WGS_CACHE_SECONDS gets the same result instead of a new check.
function wgs_round_peers($s) {
    $sig = md5(json_encode(array($s['sample_seconds'], $s['threshold_kbps'], $s['stale_seconds'], $s['confirm_checks'], $s['watch_peers'])));
    $cache = json_decode((string)@file_get_contents(WGS_CACHE_FILE), true);
    if (is_array($cache) && isset($cache['sig'], $cache['t']) && $cache['sig'] === $sig
        && (time() - (int)$cache['t']) < WGS_CACHE_SECONDS) {
        return isset($cache['peers']) ? $cache['peers'] : null;
    }
    $peers = wgs_checker_peers($s);
    if ($peers !== null) {
        $peers = wgs_apply_memory($peers, $s['confirm_checks']);
    }
    @file_put_contents(WGS_CACHE_FILE, json_encode(array('sig' => $sig, 't' => time(), 'peers' => $peers)), LOCK_EX);
    return $peers;
}

function wgs_status_badge($status) {
    $map = array(
        "streaming"       => array("success", "Streaming"),
        "connected-idle"  => array("info",    "Connected (idle)"),
        "offline"         => array("default", "Offline"),
        "never-connected" => array("default", "Never connected"),
    );
    $m = isset($map[$status]) ? $map[$status] : array("default", $status);
    return '<span class="label label-' . $m[0] . '">' . htmlspecialchars($m[1]) . '</span>';
}

function wgs_message_row($cols, $message) {
    return '<tr><td colspan="' . (int)$cols . '">' . htmlspecialchars($message) . '</td></tr>';
}

function wgs_tunnels_configured() {
    return !empty(config_get_path('installedpackages/wireguard/tunnels/item', []));
}

// The checker's peers that belong to one tunnel
function wgs_peers_of($peers, $device_name) {
    $mine = array();
    if ($peers !== null) {
        foreach ($peers as $p) {
            if ($p['interface'] === $device_name) {
                $mine[] = $p;
            }
        }
    }
    return $mine;
}

function wgs_tunnel_cells($device_name, $device) {
    return '<td>' . wg_interface_status_icon($device['status'])
        . ' <a href="wg/vpn_wg_tunnels_edit.php?tun=' . htmlspecialchars($device_name) . '">' . htmlspecialchars($device_name) . '</a></td>'
        . '<td>' . htmlspecialchars(wg_truncate_pretty($device['config']['descr'], 16)) . '</td>';
}

function wgs_traffic_cells($device) {
    return '<td>' . htmlspecialchars(format_bytes($device['transfer_rx'])) . '</td>'
        . '<td>' . htmlspecialchars(format_bytes($device['transfer_tx'])) . '</td>';
}

// Table body for WireGuard Streaming: one row per tunnel, with its peers summed
function wgs_tunnel_rows($peers) {
    if (!wgs_tunnels_configured()) {
        return wgs_message_row(5, gettext('No WireGuard tunnels have been configured.'));
    }
    $a_devices = wg_get_status();
    if (empty($a_devices)) {
        return wgs_message_row(5, gettext('The WireGuard service is not running.'));
    }

    $html = '';
    foreach ($a_devices as $device_name => $device) {
        $mine = wgs_peers_of($peers, $device_name);
        $streaming = 0;
        foreach ($mine as $p) {
            if ($p['status'] === 'streaming') {
                $streaming++;
            }
        }

        if ($peers === null) {
            $streaming_cell = '<small class="text-muted">' . htmlspecialchars(gettext('checker failed')) . '</small>';
        } else {
            $streaming_cell = htmlspecialchars($streaming . ' ' . gettext('of') . ' ' . count($mine) . ' ' . gettext('peers'));
        }

        $html .= '<tr>' . wgs_tunnel_cells($device_name, $device)
            . '<td>' . $streaming_cell . '</td>'
            . wgs_traffic_cells($device)
            . '</tr>';
    }
    return $html;
}

// Table body for WireGuard Peers: one row per peer
function wgs_peer_rows($peers, $confirm) {
    if (!wgs_tunnels_configured()) {
        return wgs_message_row(4, gettext('No WireGuard tunnels have been configured.'));
    }
    $a_devices = wg_get_status();
    if (empty($a_devices)) {
        return wgs_message_row(4, gettext('The WireGuard service is not running.'));
    }
    if ($peers === null) {
        return wgs_message_row(4, gettext('The streaming checker failed.'));
    }

    $html = '';
    foreach ($a_devices as $device_name => $device) {
        foreach (wgs_peers_of($peers, $device_name) as $p) {
            $pending = '';
            if (!empty($p['pending'])) {
                $pending = ' <small class="text-muted">' . htmlspecialchars($p['pending']) . ' '
                    . (int)$p['count'] . ' of ' . (int)$confirm . '</small>';
            }
            $html .= '<tr>'
                . '<td>' . htmlspecialchars($device_name) . '</td>'
                . '<td>' . htmlspecialchars($p['endpoint']) . '</td>'
                . '<td>' . htmlspecialchars(number_format($p['kbps'], 1)) . ' kbps</td>'
                . '<td>' . wgs_status_badge($p['status']) . $pending . '</td>'
                . '</tr>';
        }
    }
    if ($html === '') {
        return wgs_message_row(4, gettext('No peers reported by the checker.'));
    }
    return $html;
}
