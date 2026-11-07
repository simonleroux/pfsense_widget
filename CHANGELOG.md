# Changelog

Every change to the scripts is recorded here. Each script has a `VERSION` line; bump it when you change that file and note the new version in the entry below.

## 1.5.1 - 2026-11-07

### Fixed
- `widget/wireguard_streaming.widget.php` and `widget/wireguard_streaming_peers.widget.php` (VERSION 1.5.1): the two tables were capped at 300 px, so a short table made its widget shorter. Both now have a fixed height of 300 px, so the two widgets line up.
- `install.sh` (VERSION 1.5.1): the menu's "server" versions were read from raw.githubusercontent.com, which kept serving old copies (showing "UPDATE AVAILABLE, server 1.2.0" after 1.5.0 was published). The menu now reads them from the GitHub tarball once when it starts.

## 1.5.0 - 2026-11-07

### Changed
- `widget/wireguard_streaming.widget.php` (VERSION 1.5.0): the Streaming widget keeps one row per tunnel with its peers summed, and now has a fixed height with a scrollbar so it matches the Peers widget.
- `widget/wireguard_streaming_peers.widget.php` (VERSION 1.5.0), new: the WireGuard Peers widget, one row per peer (tunnel, peer, endpoint, rate, status with confirmation progress, allowed IPs). It goes in the other dashboard column, at the same fixed height.
- `widget/lib/wireguard_streaming_core.inc` (VERSION 1.5.0), new: the code both widgets share. It is installed next to the widgets, not in the title folder, so pfSense does not load it as a title file.
- The checker runs once per refresh round. A second widget that refreshes within 5 seconds reuses the result, so the failsafe counts each round once.
- `install.sh` (VERSION 1.5.0): installs the WireGuard Peers widget and the shared code as well.
- Both widgets' titles come from `widget/include/`. Widget settings are set in the WireGuard Streaming widget and apply to both.

## 1.4.0 - 2026-11-07

### Changed
- `widget/wireguard_streaming.widget.php` (VERSION 1.4.0): one row per tunnel again. The Streaming column shows how many of the tunnel's peers are streaming ("N of M peers"), and the Rate column is the sum of the tunnel's peer rates. Peer rows are gone. The table columns are Tunnel, Description, Streaming, Rate, RX, TX.

## 1.3.0 - 2026-11-07

### Changed
- `widget/wireguard_streaming.widget.php` (VERSION 1.3.0): the table has one row per peer instead of a tunnel row plus peer rows. Columns: Tunnel, Description, Peer, Rate, Status, Allowed IPs, RX, TX. The per-tunnel "Streaming" count, "Active Peers" and "Listen Port" columns are removed, and so is the Activity Threshold setting. A tunnel with no peer from the checker shows one row with its traffic totals.

## 1.2.1 - 2026-11-07

### Changed
- `widget/wireguard_streaming.widget.php` (VERSION 1.2.1): the per-peer streaming details are back. Under each tunnel row, each peer shows its key prefix, endpoint, rate, status (with the "active"/"quiet N of N" progress), and allowed IPs. The stock tunnel table and the per-tunnel "Streaming" count are unchanged.

## 1.2.0 - 2026-11-07

### Added
- `widget/wireguard_streaming.widget.php` (VERSION 1.2.0): a fork of the pfSense WireGuard dashboard widget (Apache-2.0, copyright notices kept). It keeps the stock tunnel table and adds a Streaming column ("N of M" peers streaming per tunnel), using the same checker and confirmation failsafe. Its settings are in the wrench panel, next to the stock refresh and activity settings.
- `widget/include/wireguard_streaming.inc`: dashboard title "WireGuard Streaming".

Not tested on pfSense: the fork depends on the WireGuard package's helper functions.

### Changed
- `install.sh` (VERSION 1.2.0): installs the WireGuard Streaming widget in place of the standalone widget. The standalone widget and its title file are removed on install, so only one streaming widget shows. Uninstall removes both. The stock pfSense WireGuard widget is not touched.

## 1.1.4 - 2026-11-07

### Changed
- `widget/wg_stream_monitor.widget.php` (VERSION 1.1.4): the table has an Interface column (e.g. tun_wg1) next to the Tunnel column (the pfSense description, or the interface name when there is none).

## 1.1.3 - 2026-11-07

### Fixed
- `widget/wg_stream_monitor.widget.php` (VERSION 1.1.3): the tunnel description (e.g. "St-Alphonse") was not shown and the widget showed the interface name instead. The widget now looks for the tunnel list in both `wireguard` and `installedpackages/wireguard`.

## 1.1.2 - 2026-11-07

### Changed
- `widget/wg_stream_monitor.widget.php` (VERSION 1.1.2): the confirmation rule now applies in both directions. A peer is declared streaming only after 3 checks in a row above the threshold (shown as "active 1 of 3", etc. while counting), and stops being declared streaming only after 3 checks in a row below it. The setting "Checks before streaming ends" is renamed "Checks to confirm streaming" (`confirm_checks`), so settings saved under the old name go back to the default of 3.

## 1.1.1 - 2026-11-07

### Changed
- `widget/wg_stream_monitor.widget.php` (VERSION 1.1.1): layout fix. Removed an extra closing div that pushed the other dashboard widgets out of place.

## 1.1.0 - 2026-11-07

### Added
- `widget/wg_stream_monitor.widget.php` (VERSION 1.1.0): settings are changed with the wrench icon on the widget and saved in pfSense (threshold, sample time, offline time, watch list, and checks before streaming ends). The updater no longer copies settings between files, since they are kept in pfSense.
- `widget/wg_stream_monitor.widget.php` (VERSION 1.1.0): failsafe. A peer that was streaming stays "streaming" until it is below the threshold for the configured number of checks in a row (default 3). The row shows "quiet N of 3" while it is counting. Each widget refresh counts as one check, and the state is kept in `/var/run/wg_stream_monitor.state`.

### Changed
- `install.sh` (VERSION 1.1.0): settings are no longer carried over from the old widget file on update. Values set with the old file are reset to the defaults once, so set them again with the wrench icon.

## 1.0.0 - 2026-11-07

### Added
- `install.sh` (VERSION 1.0.0): pfSense menu with install, update, status and uninstall. The header shows the installed version of the menu, checker and widget next to the version on GitHub (up to date, UPDATE AVAILABLE, or could not reach server).
- `install.sh` (VERSION 1.0.0): update downloads the latest files, reinstalls what is installed, keeps the widget settings, and restarts the menu with the new installer.
- `bootstrap.sh` (VERSION 1.0.0): one-line download and start of the menu.
- `scripts/wg-stream-check.sh` (VERSION 1.0.0): checker that reports each WireGuard peer's status as JSON.
- `widget/wg_stream_monitor.widget.php` (VERSION 1.0.0): dashboard widget. The first column shows the pfSense tunnel name (from VPN > WireGuard > Tunnels), falling back to the interface name. The "N of M peers streaming" summary line is removed.
- `widget/include/wg_stream_monitor.inc`: sets the dashboard title to "WireGuard Streaming". The widget no longer draws its own heading, so only one title shows.
