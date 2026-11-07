# Changelog

Every change to the scripts is recorded here. Each script has a `VERSION` line; bump it when you change that file and note the new version in the entry below.

## 1.0.0 - 2026-11-07

### Added
- `install.sh` (VERSION 1.0.0): pfSense menu with install, update, status and uninstall. The header shows the installed version of the menu, checker and widget next to the version on GitHub (up to date, UPDATE AVAILABLE, or could not reach server).
- `install.sh` (VERSION 1.0.0): update downloads the latest files, reinstalls what is installed, keeps the widget settings, and restarts the menu with the new installer.
- `bootstrap.sh` (VERSION 1.0.0): one-line download and start of the menu.
- `scripts/wg-stream-check.sh` (VERSION 1.0.0): checker that reports each WireGuard peer's status as JSON.
- `widget/wg_stream_monitor.widget.php` (VERSION 1.0.0): dashboard widget. The first column shows the pfSense tunnel name (from VPN > WireGuard > Tunnels), falling back to the interface name. The "N of M peers streaming" summary line is removed.
- `widget/include/wg_stream_monitor.inc`: sets the dashboard title to "WireGuard Streaming". The widget no longer draws its own heading, so only one title shows.
