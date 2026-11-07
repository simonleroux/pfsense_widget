# pfSense WireGuard Stream Monitor

A pfSense dashboard widget that shows which remote WireGuard peers are currently
passing real traffic (streaming), as opposed to just being connected.

## How it decides "streaming"

WireGuard does not report "active streams", so the check is rate-based:

1. `wg show all dump` is sampled twice, `sample_seconds` apart.
2. For each peer, the rx+tx byte delta is turned into kbps.
3. Each peer gets one status:

| Status            | Meaning                                                        |
|-------------------|----------------------------------------------------------------|
| `streaming`       | Handshake is fresh and rate is at/above `threshold_kbps`      |
| `connected-idle`  | Handshake is fresh but traffic is below the threshold          |
| `offline`         | Last handshake is older than `stale_seconds`                   |
| `never-connected` | The peer has never completed a handshake                       |

Defaults: 3 s sample, 2500 kbps (2.5 Mbps) threshold, 180 s stale. 1080p video is usually above
2.5 Mbps, so you can raise the threshold to reduce false positives from chat or
background sync. Keepalives are only a few bytes per second and stay under the threshold.

## Layout

```
install.sh                          pfSense menu: install, update, status, uninstall
bootstrap.sh                        one-line download and start of the menu
scripts/wg-stream-check.sh          POSIX sh, runs on the firewall, prints JSON
widget/wg_stream_monitor.widget.php pfSense dashboard widget (renders + polls)
```

## Install on pfSense

Requires pfSense 2.6+ with the WireGuard package. Confirm `wg` exists first:
`which wg`.

### Option A: CLI menu (recommended)

Run this on the firewall, from the console shell (option 8) or over SSH:

```sh
curl -sL https://raw.githubusercontent.com/simonleroux/pfsense_widget/main/bootstrap.sh | sudo sh
```

It downloads the project to `/root/pfsense_widget` and opens the menu:

```
=====================================
  WireGuard Stream Monitor (pfSense)
=====================================
  Menu version:    1.0.0 (up to date)
  Checker:         not installed (...)
  Widget:          not installed (...)
=====================================
1) Install checker and widget
2) Update installed (keeps your widget settings)
3) Check status
4) Uninstall
0) Exit
```

- **Install** copies the checker and widget into place.
- **Update installed** downloads the latest version from GitHub and reinstalls what is installed. Your widget settings are kept, and the menu restarts with the new version.
- **Check status** shows the installed versions, checks `wg`, and runs the checker once.
- **Uninstall** removes the checker and widget. Remove the widget from the dashboard yourself.

Existing files are backed up as `*.bak` before being replaced.

To run the menu from a copy you already have on the firewall: `sh /root/pfsense-wg-stream-widget/install.sh`

### Option B: Manual copy

Run these over SSH on the firewall (or use Diagnostics > Command Prompt):

```sh
cp scripts/wg-stream-check.sh /usr/local/bin/wg-stream-check.sh
chmod 755 /usr/local/bin/wg-stream-check.sh
cp widget/wg_stream_monitor.widget.php /usr/local/www/widgets/widgets/wg_stream_monitor.widget.php
```

Then in the web UI: Dashboard > **+** > add **WireGuard Streaming**.

Test the checker by hand before relying on the widget:

```sh
/usr/local/bin/wg-stream-check.sh 3 100 180
```

Start a stream on a remote device and run it again. That device should show `"status":"streaming"`.

## Tuning

Edit the settings block at the top of the widget file:

- `$threshold_kbps`: raise it to ignore light traffic.
- `$watch_peers`: limit the widget to one device, e.g. `array("10.10.0.23/32")`.

## Limitations

- Rate-based heuristic. A large file transfer also shows as `streaming`, and a
  low-bitrate stream may show as `connected-idle`.
- Counters are measured at the WireGuard interface, so this reflects traffic
  through the tunnel, not what the device is doing.
- Peers show as public-key prefixes. Friendly names are not mapped yet.
- Each refresh blocks for `sample_seconds` on the firewall.

## Roadmap

- Map public keys to friendly names from the pfSense WireGuard config.
- Optional alert when a watched device starts or stops streaming.
- Short rate history per peer.

## Development notes

- The widget API details (`guiconfig.inc`, how dashboard widgets are discovered
  and included) should be checked against the source for your pfSense version,
  under `src/usr/local/www/widgets/` in the pfSense repository.
- The script has been tested with a stub `wg`; test it on the firewall before deploying.
