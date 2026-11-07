<?php
/*
 * wireguard_streaming.widget.php
 *
 * Fork of pfSense's WireGuard dashboard widget (wireguard.widget.php from the pfSense
 * WireGuard package) with a Streaming column added. Streaming comes from
 * scripts/wg-stream-check.sh, with the same confirmation failsafe as
 * wg_stream_monitor.widget.php.
 *
 * Based on the pfSense WireGuard package widget:
 *   Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 *   Copyright (c) 2021 R. Christian McDonald, Vajonam
 *   Copyright (c) 2020 Ascrod
 *   Licensed under the Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 * Changes in this fork: streaming column, streaming settings, failsafe.
 *
 * Install: copy to /usr/local/www/widgets/widgets/wireguard_streaming.widget.php
 *          and widget/include/wireguard_streaming.inc to /usr/local/www/widgets/include/
 * Replace the stock WireGuard widget on the dashboard with this one (or keep both).
 * VERSION="1.2.0"
 */

// pfSense includes
require_once('guiconfig.inc');
require_once('util.inc');

// WireGuard includes
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_guiconfig.inc');

// Widget include: dashboard title
require_once('/usr/local/www/widgets/include/wireguard_streaming.inc');

global $wgg;

wg_globals();

// ---- Fixed settings ----------------------------------------------------------
$checker    = "/usr/local/bin/wg-stream-check.sh";
// Each peer's streaming state between checks (for the failsafe). Separate from the standalone widget.
$state_file = "/var/run/wireguard_streaming.state";

// Defaults for the streaming settings, used until saved with the wrench icon.
$wgs_defaults = array(
    'threshold_kbps' => 100,  // rx+tx rate at/above which a peer is "streaming" (1-1000000)
    'confirm_checks' => 3,    // checks in a row needed to declare streaming started or ended (1-10)
    'sample_seconds' => 3,    // time between the two counter samples (1-30)
    'stale_seconds'  => 180,  // handshake older than this = offline (10-3600)
    'watch_peers'    => '',   // optional: comma-separated public key prefixes or allowed IPs
);
// ----------------------------------------------------------------------------

if (!isset($user_settings) || !is_array($user_settings)) {
    $user_settings = array();
}

function wgs_valid_key($key) {
    return is_string($key) && preg_match('/^wireguard_streaming-\d+$/', $key) === 1;
}

// Dashboard key for this instance (e.g. wireguard_streaming-0)
$widgetkey_local = "wireguard_streaming-0";
if (isset($widgetkey) && wgs_valid_key($widgetkey)) {
    $widgetkey_local = $widgetkey;
} elseif (isset($_REQUEST['widgetkey']) && wgs_valid_key($_REQUEST['widgetkey'])) {
    $widgetkey_local = $_REQUEST['widgetkey'];
}

function wgs_int($value, $min, $max, $default) {
    $n = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => $min, 'max_range' => $max)));
    return ($n === false) ? $default : $n;
}

// Turns saved or posted values into safe settings. Includes the stock refresh and activity settings.
function wgs_clean_settings($raw, $defaults, $wgg) {
    $raw = is_array($raw) ? $raw : array();
    $watch = isset($raw['watch_peers']) ? (string)$raw['watch_peers'] : '';
    return array(
        'refresh_interval'  => wgs_int(isset($raw['refresh_interval']) ? $raw['refresh_interval'] : null, 0, 10, $wgg['default_widget_refresh_interval']),
        'activity_threshold' => wgs_int(isset($raw['activity_threshold']) ? $raw['activity_threshold'] : null, 0, 31536000, $wgg['default_widget_activity_threshold']),
        'threshold_kbps' => wgs_int(isset($raw['threshold_kbps']) ? $raw['threshold_kbps'] : null, 1, 1000000, $defaults['threshold_kbps']),
        'confirm_checks' => wgs_int(isset($raw['confirm_checks']) ? $raw['confirm_checks'] : null, 1, 10, $defaults['confirm_checks']),
        'sample_seconds' => wgs_int(isset($raw['sample_seconds']) ? $raw['sample_seconds'] : null, 1, 30, $defaults['sample_seconds']),
        'stale_seconds'  => wgs_int(isset($raw['stale_seconds']) ? $raw['stale_seconds'] : null, 10, 3600, $defaults['stale_seconds']),
        'watch_peers'    => substr(preg_replace('/[^A-Za-z0-9+\/=_.:, -]/', '', $watch), 0, 500),
    );
}

$widget_config = isset($user_settings['widgets'][$widgetkey_local]) ? $user_settings['widgets'][$widgetkey_local] : array();
$settings = wgs_clean_settings($widget_config, $wgs_defaults, $wgg);

// ---- Save settings (form in the wrench panel posts back to this file) --------
if (isset($_POST['save']) && isset($_POST['widgetkey']) && wgs_valid_key($_POST['widgetkey'])) {
    $key = $_POST['widgetkey'];
    $existing = isset($user_settings['widgets'][$key]) && is_array($user_settings['widgets'][$key]) ? $user_settings['widgets'][$key] : array();
    $user_settings['widgets'][$key] = array_merge($existing, wgs_clean_settings($_POST, $wgs_defaults, $wgg));
    save_widget_settings($_SESSION['Username'], $user_settings['widgets'], gettext("Updated WireGuard Streaming widget settings via dashboard."));
    header('Location: /');
    exit;
}

// Runs the checker and returns its peers, or null if it fails. Applies the watch list.
function wgs_checker_peers($checker, $s) {
    $cmd = escapeshellcmd($checker) . ' ' . intval($s['sample_seconds']) . ' ' . intval($s['threshold_kbps']) . ' ' . intval($s['stale_seconds']) . ' 2>/dev/null';
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
function wgs_apply_memory($peers, $confirm, $state_file) {
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
            $peers[$i]['status'] = 'streaming';
        } elseif (!$now['streaming'] && $raw) {
            $peers[$i]['status'] = 'connected-idle';
        }
    }
    @file_put_contents($state_file, json_encode($new), LOCK_EX);
    return $peers;
}

// Per tunnel: how many watched peers are declared streaming, out of how many are shown
function wgs_stream_counts($peers) {
    $counts = array();
    foreach ($peers as $p) {
        $if = $p['interface'];
        if (!isset($counts[$if])) {
            $counts[$if] = array('streaming' => 0, 'total' => 0);
        }
        $counts[$if]['total']++;
        if ($p['status'] === 'streaming') {
            $counts[$if]['streaming']++;
        }
    }
    return $counts;
}

// Table body: one row per tunnel, as in the stock widget, plus the streaming column
function wgs_compose_body($widgetkey, $activity_threshold, $s, $checker, $state_file) {
    $a_devices = wg_get_status();
    $current_time = (new DateTimeImmutable)->getTimestamp();

    if (empty(config_get_path('installedpackages/wireguard/tunnels/item', []))) {
        return '<tr><td colspan="8">' . htmlspecialchars(gettext('No WireGuard tunnels have been configured.')) . '</td></tr>';
    }
    if (empty($a_devices)) {
        return '<tr><td colspan="8">' . htmlspecialchars(gettext('The WireGuard service is not running.')) . '</td></tr>';
    }

    $peers = wgs_checker_peers($checker, $s);
    if ($peers !== null) {
        $peers = wgs_apply_memory($peers, $s['confirm_checks'], $state_file);
    }
    $counts = ($peers === null) ? array() : wgs_stream_counts($peers);

    $html = '';
    foreach ($a_devices as $device_name => $device) {
        $active_peers = array_filter($device['peers'], function ($x) use ($current_time, $activity_threshold) {
            return (($activity_threshold == 0) || (($current_time - $x['latest_handshake']) <= $activity_threshold));
        });

        if ($peers === null) {
            $streaming_cell = htmlspecialchars(gettext('checker failed'));
        } else {
            $c = isset($counts[$device_name]) ? $counts[$device_name] : array('streaming' => 0, 'total' => 0);
            $streaming_cell = htmlspecialchars($c['streaming'] . ' ' . gettext('of') . ' ' . $c['total']);
        }

        $html .= '<tr>'
            . '<td>' . wg_interface_status_icon($device['status'])
            . ' <a href="wg/vpn_wg_tunnels_edit.php?tun=' . htmlspecialchars($device_name) . '">' . htmlspecialchars($device_name) . '</a></td>'
            . '<td>' . htmlspecialchars(wg_truncate_pretty($device['config']['descr'], 16)) . '</td>'
            . '<td>' . htmlspecialchars(count($active_peers)) . '</td>'
            . '<td>' . $streaming_cell . '</td>'
            . '<td>' . htmlspecialchars($device['listen_port']) . '</td>'
            . '<td>' . htmlspecialchars(format_bytes($device['transfer_rx'])) . '</td>'
            . '<td>' . htmlspecialchars(format_bytes($device['transfer_tx'])) . '</td>'
            . '</tr>';
    }
    return $html;
}

// ---- AJAX refresh: the dashboard's refresh system posts here -----------------
if (isset($_POST['ajax'])) {
    print(wgs_compose_body($widgetkey_local, $settings['activity_threshold'], $settings, $checker, $state_file));
    exit();
}
?>
	<div class="table-responsive">
		<table class="table table-hover table-striped table-condensed" style="overflow-x: visible;">
			<thead>
				<th><?=gettext('Tunnel')?></th>
				<th><?=gettext('Description')?></th>
				<th><?=(($settings['activity_threshold'] == 0) ? gettext('Peers') : gettext('Active Peers'))?></th>
				<th><?=gettext('Streaming')?></th>
				<th><?=gettext('Listen Port')?></th>
				<th><?=gettext('RX')?></th>
				<th><?=gettext('TX')?></th>
			</thead>
			<tbody id="<?=htmlspecialchars($widgetkey_local)?>">
				<?=wgs_compose_body($widgetkey_local, $settings['activity_threshold'], $settings, $checker, $state_file)?>
			</tbody>
		</table>
	</div>
</div>

<?php
$footer_id = isset($widget_panel_footer_id) ? $widget_panel_footer_id : 'widget-' . $widgetkey_local . '_panel-footer';
?>
<div id="<?=htmlspecialchars($footer_id)?>" class="panel-footer collapse">

	<form action="/widgets/widgets/wireguard_streaming.widget.php" method="post" class="form-horizontal">
		<input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey_local)?>" />
		<input type="hidden" name="save" value="save" />

		<div class="form-group">
			<label for="refresh_interval" class="col-sm-4 control-label"><?=gettext('Refresh Interval')?></label>
			<div class="col-sm-8">
				<input type="number" id="refresh_interval" name="refresh_interval" value="<?=htmlspecialchars($settings['refresh_interval'])?>" min="0" max="10" class="form-control" />
				<span class="help-block"><?=gettext('Widget refresh interval (in ticks, 0 to disable).')?></span>
			</div>
		</div>

		<div class="form-group">
			<label for="activity_threshold" class="col-sm-4 control-label"><?=gettext('Activity Threshold')?></label>
			<div class="col-sm-8">
				<input type="number" id="activity_threshold" name="activity_threshold" value="<?=htmlspecialchars($settings['activity_threshold'])?>" min="0" class="form-control" />
				<span class="help-block"><?=gettext('Peer activity threshold in seconds (0 to show all peers).')?></span>
			</div>
		</div>

		<div class="form-group">
			<label for="threshold_kbps" class="col-sm-4 control-label"><?=gettext('Streaming threshold (kbps)')?></label>
			<div class="col-sm-8">
				<input type="number" id="threshold_kbps" name="threshold_kbps" value="<?=htmlspecialchars($settings['threshold_kbps'])?>" min="1" max="1000000" class="form-control" />
				<span class="help-block"><?=gettext('Combined rx+tx rate at or above this counts as streaming.')?></span>
			</div>
		</div>

		<div class="form-group">
			<label for="confirm_checks" class="col-sm-4 control-label"><?=gettext('Checks to confirm streaming')?></label>
			<div class="col-sm-8">
				<input type="number" id="confirm_checks" name="confirm_checks" value="<?=htmlspecialchars($settings['confirm_checks'])?>" min="1" max="10" class="form-control" />
				<span class="help-block"><?=gettext('Checks in a row needed before streaming is declared to start or end.')?></span>
			</div>
		</div>

		<div class="form-group">
			<label for="sample_seconds" class="col-sm-4 control-label"><?=gettext('Sample time (seconds)')?></label>
			<div class="col-sm-8">
				<input type="number" id="sample_seconds" name="sample_seconds" value="<?=htmlspecialchars($settings['sample_seconds'])?>" min="1" max="30" class="form-control" />
				<span class="help-block"><?=gettext('Time between the two counter samples. Each refresh blocks for this long.')?></span>
			</div>
		</div>

		<div class="form-group">
			<label for="stale_seconds" class="col-sm-4 control-label"><?=gettext('Offline after (seconds)')?></label>
			<div class="col-sm-8">
				<input type="number" id="stale_seconds" name="stale_seconds" value="<?=htmlspecialchars($settings['stale_seconds'])?>" min="10" max="3600" class="form-control" />
				<span class="help-block"><?=gettext('Peers with a last handshake older than this show as offline.')?></span>
			</div>
		</div>

		<div class="form-group">
			<label for="watch_peers" class="col-sm-4 control-label"><?=gettext('Only count these peers')?></label>
			<div class="col-sm-8">
				<input type="text" id="watch_peers" name="watch_peers" value="<?=htmlspecialchars($settings['watch_peers'])?>" class="form-control" />
				<span class="help-block"><?=gettext('Comma-separated public key prefixes or allowed IPs. Empty = all peers.')?></span>
			</div>
		</div>

		<nav class="action-buttons">
			<button type="submit" class="btn btn-primary">
				<i class="fa-solid fa-save icon-embed-btn"></i>
				<?=gettext('Save')?>
			</button>
		</nav>
	</form>

	<script type="text/javascript">
	//<![CDATA[
	events.push(function(){

		var wgsRefreshInterval = <?=json_encode($settings['refresh_interval'])?>;

		// Callback function called by refresh system when data is retrieved
		function wgs_callback(s) {
			$(<?=json_encode("#{$widgetkey_local}")?>).html(s);
		}

		// POST data to send via AJAX
		var postdata = {
			ajax: "ajax",
			widgetkey: <?=json_encode($widgetkey_local)?>
		};

		if (wgsRefreshInterval > 0) {

			var wgsObject = new Object();
			wgsObject.name = "wireguard_streaming";
			wgsObject.url = "/widgets/widgets/wireguard_streaming.widget.php";
			wgsObject.callback = wgs_callback;
			wgsObject.parms = postdata;
			wgsObject.freq = wgsRefreshInterval;

			register_ajax(wgsObject);

		}

	});
	//]]>
	</script>
