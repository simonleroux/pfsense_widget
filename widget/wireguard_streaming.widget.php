<?php
/*
 * wireguard_streaming.widget.php - "WireGuard Streaming" dashboard widget.
 *
 * One row per tunnel, with its peers summed (how many are streaming, and their combined
 * rate). Holds the streaming settings for both this widget and "WireGuard Peers".
 * Fork of pfSense's WireGuard dashboard widget (wireguard.widget.php from the pfSense
 * WireGuard package), with streaming columns added.
 *
 * Based on the pfSense WireGuard package widget:
 *   Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 *   Copyright (c) 2021 R. Christian McDonald, Vajonam
 *   Copyright (c) 2020 Ascrod
 *   Licensed under the Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 *
 * Install: copy to /usr/local/www/widgets/widgets/wireguard_streaming.widget.php
 *          with widget/lib/wireguard_streaming_core.inc in the same folder, and
 *          widget/include/wireguard_streaming.inc to /usr/local/www/widgets/include/
 * VERSION="1.5.4"
 */

// pfSense includes
require_once('guiconfig.inc');
require_once('util.inc');

// WireGuard includes
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_guiconfig.inc');

// Widget title, and the code shared with the Peers widget
require_once('/usr/local/www/widgets/include/wireguard_streaming.inc');
require_once('/usr/local/www/widgets/widgets/wireguard_streaming_core.inc');

global $wgg;

wg_globals();

if (!isset($user_settings) || !is_array($user_settings)) {
    $user_settings = array();
}

// Dashboard key for this instance (e.g. wireguard_streaming-0)
$widgetkey_local = 'wireguard_streaming-0';
if (isset($widgetkey) && wgs_valid_key($widgetkey, 'wireguard_streaming')) {
    $widgetkey_local = $widgetkey;
} elseif (isset($_REQUEST['widgetkey']) && wgs_valid_key($_REQUEST['widgetkey'], 'wireguard_streaming')) {
    $widgetkey_local = $_REQUEST['widgetkey'];
}

$settings = wgs_load_settings($user_settings, $wgg);

// ---- Save settings (form in the wrench panel posts back to this file) --------
if (isset($_POST['save']) && isset($_POST['widgetkey']) && wgs_valid_key($_POST['widgetkey'], 'wireguard_streaming')) {
    $existing = isset($user_settings['widgets'][WGS_SETTINGS_KEY]) && is_array($user_settings['widgets'][WGS_SETTINGS_KEY])
        ? $user_settings['widgets'][WGS_SETTINGS_KEY] : array();
    $user_settings['widgets'][WGS_SETTINGS_KEY] = array_merge($existing, wgs_clean_settings($_POST, $wgg));
    save_widget_settings($_SESSION['Username'], $user_settings['widgets'], gettext("Updated WireGuard Streaming widget settings via dashboard."));
    header('Location: /');
    exit;
}

// ---- AJAX refresh: the dashboard's refresh system posts here -----------------
if (isset($_POST['ajax'])) {
    print(wgs_tunnel_rows(wgs_round_peers($settings)));
    exit();
}
?>
	<div class="table-responsive">
		<table class="table table-hover table-striped table-condensed" style="overflow-x: visible;">
			<thead>
				<th><?=gettext('Tunnel')?></th>
				<th><?=gettext('Description')?></th>
				<th><?=gettext('Streaming')?></th>
				<th><?=gettext('RX')?></th>
				<th><?=gettext('TX')?></th>
			</thead>
			<tbody id="<?=htmlspecialchars($widgetkey_local)?>">
				<?=wgs_tunnel_rows(wgs_round_peers($settings))?>
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
				<span class="help-block"><?=gettext('Widget refresh interval (in ticks, 0 to disable). Also used by the WireGuard Peers widget.')?></span>
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
			<label for="sample_seconds" class="col-sm-4 control-label"><?=gettext('Minimum sample gap (seconds)')?></label>
			<div class="col-sm-8">
				<input type="number" id="sample_seconds" name="sample_seconds" value="<?=htmlspecialchars($settings['sample_seconds'])?>" min="1" max="30" class="form-control" />
				<span class="help-block"><?=gettext('Minimum time between counter samples. Rates are measured between refreshes, and calls sooner than this reuse the last rate.')?></span>
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
			<label for="watch_peers" class="col-sm-4 control-label"><?=gettext('Only show these peers')?></label>
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
