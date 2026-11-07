# Changelog

Every change to the scripts is recorded here. Each script has a `VERSION` line; bump it when you change that file and note the new version in the entry below.

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
