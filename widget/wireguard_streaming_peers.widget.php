<?php
/*
 * wireguard_streaming_peers.widget.php - "WireGuard Peers" dashboard widget.
 *
 * One row per peer: tunnel, peer, endpoint, rate, streaming status (with the confirmation
 * progress) and allowed IPs. Uses the settings of the WireGuard Streaming widget.
 *
 * Install: copy to /usr/local/www/widgets/widgets/wireguard_streaming_peers.widget.php
 *          with widget/lib/wireguard_streaming_core.inc in the same folder, and
 *          widget/include/wireguard_streaming_peers.inc to /usr/local/www/widgets/include/
 * VERSION="1.5.4"
 */

// pfSense includes
require_once('guiconfig.inc');
require_once('util.inc');

// WireGuard includes
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_guiconfig.inc');

// Widget title, and the code shared with the Streaming widget
require_once('/usr/local/www/widgets/include/wireguard_streaming_peers.inc');
require_once('/usr/local/www/widgets/widgets/wireguard_streaming_core.inc');

global $wgg;

wg_globals();

if (!isset($user_settings) || !is_array($user_settings)) {
    $user_settings = array();
}

// Dashboard key for this instance (e.g. wireguard_streaming_peers-0)
$widgetkey_local = 'wireguard_streaming_peers-0';
if (isset($widgetkey) && wgs_valid_key($widgetkey, 'wireguard_streaming_peers')) {
    $widgetkey_local = $widgetkey;
} elseif (isset($_REQUEST['widgetkey']) && wgs_valid_key($_REQUEST['widgetkey'], 'wireguard_streaming_peers')) {
    $widgetkey_local = $_REQUEST['widgetkey'];
}

$settings = wgs_load_settings($user_settings, $wgg);

// ---- AJAX refresh: the dashboard's refresh system posts here -----------------
if (isset($_POST['ajax'])) {
    print(wgs_peer_rows(wgs_round_peers($settings), $settings['confirm_checks']));
    exit();
}
?>
	<div class="table-responsive">
		<table class="table table-hover table-striped table-condensed" style="overflow-x: visible;">
			<thead>
				<th><?=gettext('Tunnel')?></th>
				<th><?=gettext('Endpoint')?></th>
				<th><?=gettext('Rate')?></th>
				<th><?=gettext('Status')?></th>
			</thead>
			<tbody id="<?=htmlspecialchars($widgetkey_local)?>">
				<?=wgs_peer_rows(wgs_round_peers($settings), $settings['confirm_checks'])?>
			</tbody>
		</table>
	</div>

	<script type="text/javascript">
	//<![CDATA[
	events.push(function(){

		var wgsPeersRefreshInterval = <?=json_encode($settings['refresh_interval'])?>;

		// Callback function called by refresh system when data is retrieved
		function wgsPeers_callback(s) {
			$(<?=json_encode("#{$widgetkey_local}")?>).html(s);
		}

		// POST data to send via AJAX
		var postdata = {
			ajax: "ajax",
			widgetkey: <?=json_encode($widgetkey_local)?>
		};

		if (wgsPeersRefreshInterval > 0) {

			var wgsPeersObject = new Object();
			wgsPeersObject.name = "wireguard_streaming_peers";
			wgsPeersObject.url = "/widgets/widgets/wireguard_streaming_peers.widget.php";
			wgsPeersObject.callback = wgsPeers_callback;
			wgsPeersObject.parms = postdata;
			wgsPeersObject.freq = wgsPeersRefreshInterval;

			register_ajax(wgsPeersObject);

		}

	});
	//]]>
	</script>
