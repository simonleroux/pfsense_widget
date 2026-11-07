<?php
/*
 * wan_throughput.widget.php - WAN throughput over the last 5 minutes, as a graph.
 *
 * Each refresh reads the WAN interface's byte counters (netstat) and adds a sample to a
 * short history kept in /var/run. The graph is drawn as SVG: download (blue) and
 * upload (green), newest on the right.
 *
 * Install: copy to /usr/local/www/widgets/widgets/wan_throughput.widget.php
 *          and widget/include/wan_throughput.inc to /usr/local/www/widgets/include/
 * VERSION="1.0.0"
 */

require_once('guiconfig.inc');
require_once('util.inc');
require_once('/usr/local/www/widgets/include/wan_throughput.inc');

// ---- Fixed settings ----------------------------------------------------------
$wt_history_file = '/var/run/wan_throughput.json';
$wt_window       = 300;   // seconds shown in the graph (5 minutes)
$wt_min_gap      = 2;     // samples closer than this are ignored (several tabs refresh together)
$wt_scale_min    = 1000;  // kbps; the graph is at least this tall (1 Mbps)
// ----------------------------------------------------------------------------

// The WAN interface name, from the pfSense config (e.g. igb0)
function wt_wan_if() {
    $if = config_get_path('interfaces/wan/if', '');
    return preg_match('/^[a-z0-9_.]+$/', (string)$if) ? (string)$if : '';
}

// Cumulative bytes in and out for the interface, or null if they can't be read
function wt_counters($if) {
    if ($if === '') {
        return null;
    }
    $out = shell_exec('netstat -bI ' . escapeshellarg($if) . ' -n 2>/dev/null');
    foreach (explode("\n", (string)$out) as $line) {
        $f = preg_split('/\s+/', trim($line));
        $n = count($f);
        // Columns: Name Mtu Network Address Ipkts Ierrs Ibytes Opkts Oerrs Obytes Coll
        if ($n >= 10 && $f[0] === $if) {
            return array('in' => (float)$f[$n - 4], 'out' => (float)$f[$n - 1]);
        }
    }
    return null;
}

// Adds a sample if one is due, drops samples older than the window, and returns the history.
// Each sample: t (time), in/out (counters), down/up (kbps since the previous sample, null for the first).
function wt_update_history($if, $file, $window, $min_gap) {
    $now = microtime(true);
    $hist = json_decode((string)@file_get_contents($file), true);
    if (!is_array($hist)) {
        $hist = array();
    }

    $c = wt_counters($if);
    if ($c !== null && (count($hist) === 0 || ($now - end($hist)['t']) >= $min_gap)) {
        $prev = count($hist) > 0 ? end($hist) : null;
        $sample = array('t' => $now, 'in' => $c['in'], 'out' => $c['out'], 'down' => null, 'up' => null);
        if ($prev !== null) {
            $dt = $now - $prev['t'];
            $din = $c['in'] - $prev['in'];
            $dout = $c['out'] - $prev['out'];
            if ($din < 0 || $dout < 0) {
                // Counter went backwards (reboot or interface reset): no rate for this sample
                $din = 0;
                $dout = 0;
                $sample['down'] = null;
                $sample['up'] = null;
            } else {
                $sample['down'] = $din * 8 / 1000 / $dt;
                $sample['up'] = $dout * 8 / 1000 / $dt;
            }
        }
        $hist[] = $sample;
        @file_put_contents($file, json_encode($hist), LOCK_EX);
    }

    $hist = array_values(array_filter($hist, function ($s) use ($now, $window) {
        return ($now - $s['t']) <= $window;
    }));
    return array($hist, $now);
}

// SVG line chart of the history
function wt_svg($hist, $now, $window, $scale_min) {
    $w = 600; $h = 180; $pad = 28;
    $max = $scale_min;
    foreach ($hist as $s) {
        if ($s['down'] !== null) {
            $max = max($max, $s['down'], $s['up']);
        }
    }

    $down = array();
    $up = array();
    foreach ($hist as $s) {
        if ($s['down'] === null) {
            continue;
        }
        $x = $pad + ($w - 2 * $pad) * (1 - ($now - $s['t']) / $window);
        $yd = $h - $pad - ($h - 2 * $pad) * ($s['down'] / $max);
        $yu = $h - $pad - ($h - 2 * $pad) * ($s['up'] / $max);
        $down[] = round($x, 1) . ',' . round(max($pad, min($h - $pad, $yd)), 1);
        $up[] = round($x, 1) . ',' . round(max($pad, min($h - $pad, $yu)), 1);
    }

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" style="max-height:200px">';
    $svg .= '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="none"/>';
    $svg .= '<line x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad) . '" y2="' . ($h - $pad) . '" stroke="#999"/>';
    $svg .= '<line x1="' . $pad . '" y1="' . $pad . '" x2="' . $pad . '" y2="' . ($h - $pad) . '" stroke="#999"/>';
    $svg .= '<text x="' . ($pad - 4) . '" y="' . ($pad + 4) . '" font-size="10" text-anchor="end">' . htmlspecialchars(number_format($max / 1000, 1)) . '</text>';
    $svg .= '<text x="' . ($pad - 4) . '" y="' . ($h - $pad) . '" font-size="10" text-anchor="end">0</text>';
    $svg .= '<text x="' . $pad . '" y="' . ($h - 6) . '" font-size="10">-' . (int)($window / 60) . ' min</text>';
    $svg .= '<text x="' . ($w - $pad) . '" y="' . ($h - 6) . '" font-size="10" text-anchor="end">now</text>';
    $svg .= '<text x="' . ($pad + 4) . '" y="12" font-size="10">Mbps</text>';
    if (count($down) > 1) {
        $svg .= '<polyline fill="none" stroke="#1f77b4" stroke-width="2" points="' . implode(' ', $down) . '"/>';
        $svg .= '<polyline fill="none" stroke="#2ca02c" stroke-width="2" points="' . implode(' ', $up) . '"/>';
    }
    $svg .= '</svg>';
    return $svg;
}

// Current and 5-minute peak values, as text
function wt_summary($hist) {
    $cur_down = null; $cur_up = null; $peak_down = 0; $peak_up = 0;
    foreach ($hist as $s) {
        if ($s['down'] === null) {
            continue;
        }
        $cur_down = $s['down'];
        $cur_up = $s['up'];
        $peak_down = max($peak_down, $s['down']);
        $peak_up = max($peak_up, $s['up']);
    }
    if ($cur_down === null) {
        return '<small class="text-muted">' . htmlspecialchars(gettext('Sampling...')) . '</small>';
    }
    return '<p>'
        . '<strong>' . htmlspecialchars(gettext('Download')) . ':</strong> ' . htmlspecialchars(number_format($cur_down / 1000, 2)) . ' Mbps'
        . ' <small class="text-muted">(' . htmlspecialchars(gettext('peak')) . ' ' . htmlspecialchars(number_format($peak_down / 1000, 2)) . ')</small>'
        . ' &nbsp; <strong>' . htmlspecialchars(gettext('Upload')) . ':</strong> ' . htmlspecialchars(number_format($cur_up / 1000, 2)) . ' Mbps'
        . ' <small class="text-muted">(' . htmlspecialchars(gettext('peak')) . ' ' . htmlspecialchars(number_format($peak_up / 1000, 2)) . ')</small>'
        . '</p>';
}

function wt_render() {
    global $wt_history_file, $wt_window, $wt_min_gap, $wt_scale_min;
    $if = wt_wan_if();
    if ($if === '') {
        return '<p class="text-danger">' . htmlspecialchars(gettext('No WAN interface found in the configuration.')) . '</p>';
    }
    list($hist, $now) = wt_update_history($if, $wt_history_file, $wt_window, $wt_min_gap);
    if (wt_counters($if) === null) {
        return '<p class="text-danger">' . htmlspecialchars(sprintf(gettext('Could not read the counters for %s.'), $if)) . '</p>';
    }
    return wt_summary($hist) . wt_svg($hist, $now, $wt_window, $wt_scale_min);
}

// ---- AJAX refresh: the dashboard's refresh system posts here -----------------
if (isset($_POST['ajax'])) {
    print(wt_render());
    exit();
}

$wt_key = isset($widgetkey) && preg_match('/^wan_throughput-\d+$/', $widgetkey) ? $widgetkey : 'wan_throughput-0';
?>
<div id="<?=htmlspecialchars($wt_key)?>-body">
	<?=wt_render()?>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function(){

	// Callback function called by refresh system when data is retrieved
	function wt_callback(s) {
		$(<?=json_encode("#{$wt_key}-body")?>).html(s);
	}

	// POST data to send via AJAX
	var postdata = {
		ajax: "ajax"
	};

	var wtObject = new Object();
	wtObject.name = "wan_throughput";
	wtObject.url = "/widgets/widgets/wan_throughput.widget.php";
	wtObject.callback = wt_callback;
	wtObject.parms = postdata;
	wtObject.freq = 1;

	register_ajax(wtObject);

});
//]]>
</script>
