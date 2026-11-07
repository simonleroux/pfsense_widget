#!/bin/sh
#
# install.sh - interactive menu to install the WireGuard Stream Monitor on pfSense.
#
# Run on the firewall as root, from the directory where you copied this repo:
#   sh /root/pfsense-wg-stream-widget/install.sh
# (Or from Diagnostics > Command Prompt, or option 8 "Shell" in the console menu.)
#
# Target paths can be overridden for testing:
#   WG_STREAM_BIN_DIR, WG_STREAM_WIDGET_DIR, WG_STREAM_SKIP_ROOT_CHECK=1

set -u

SRC_DIR=$(cd "$(dirname "$0")" && pwd)
CHECKER_SRC="$SRC_DIR/scripts/wg-stream-check.sh"
WIDGET_SRC="$SRC_DIR/widget/wg_stream_monitor.widget.php"

BIN_DIR=${WG_STREAM_BIN_DIR:-/usr/local/bin}
WIDGET_DIR=${WG_STREAM_WIDGET_DIR:-/usr/local/www/widgets/widgets}
CHECKER="$BIN_DIR/wg-stream-check.sh"
WIDGET="$WIDGET_DIR/wg_stream_monitor.widget.php"

say()  { printf '%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
ok()   { printf '  [ok] %s\n' "$*"; }
bad()  { printf '  [!!] %s\n' "$*"; }

pause() {
    printf '\nPress Enter to continue... '
    read -r _ || true
}

confirm() {
    printf '%s [y/N]: ' "$1"
    read -r answer || return 1
    case "$answer" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

need_root() {
    if [ "${WG_STREAM_SKIP_ROOT_CHECK:-0}" = "1" ]; then
        return 0
    fi
    if [ "$(id -u)" -ne 0 ]; then
        warn "run this as root (for example, from the pfSense console shell)."
        exit 1
    fi
}

check_sources() {
    missing=0
    [ -f "$CHECKER_SRC" ] || { warn "missing $CHECKER_SRC"; missing=1; }
    [ -f "$WIDGET_SRC" ]  || { warn "missing $WIDGET_SRC"; missing=1; }
    return $missing
}

install_checker() {
    [ -f "$CHECKER_SRC" ] || { warn "missing $CHECKER_SRC"; return 1; }
    mkdir -p "$BIN_DIR" || { warn "cannot create $BIN_DIR"; return 1; }
    if [ -f "$CHECKER" ]; then
        cp "$CHECKER" "$CHECKER.bak" && say "  Backed up existing checker to $CHECKER.bak"
    fi
    if cp "$CHECKER_SRC" "$CHECKER" && chmod 755 "$CHECKER"; then
        ok "checker installed at $CHECKER"
    else
        warn "failed to install checker to $CHECKER"
        return 1
    fi
}

install_widget() {
    [ -f "$WIDGET_SRC" ] || { warn "missing $WIDGET_SRC"; return 1; }
    if [ ! -d "$WIDGET_DIR" ]; then
        warn "$WIDGET_DIR not found. Is this a pfSense firewall with the web GUI installed?"
        return 1
    fi
    if [ -f "$WIDGET" ]; then
        cp "$WIDGET" "$WIDGET.bak" && say "  Backed up existing widget to $WIDGET.bak"
    fi
    if cp "$WIDGET_SRC" "$WIDGET" && chmod 644 "$WIDGET"; then
        ok "widget installed at $WIDGET"
    else
        warn "failed to install widget to $WIDGET"
        return 1
    fi
}

check_wg() {
    if command -v wg >/dev/null 2>&1; then
        ok "wg found at $(command -v wg)"
    else
        bad "wg not found. Install the WireGuard package in pfSense (System > Package Manager)."
    fi
}

test_checker() {
    if [ ! -x "$CHECKER" ]; then
        warn "checker is not installed yet. Install it first (option 1 or 2)."
        return 1
    fi
    say "Running: $CHECKER 3 100 180  (takes about 3 seconds)"
    "$CHECKER" 3 100 180
    say ""
}

show_status() {
    say "Source files (in $SRC_DIR):"
    if [ -f "$CHECKER_SRC" ]; then ok "$CHECKER_SRC"; else bad "$CHECKER_SRC"; fi
    if [ -f "$WIDGET_SRC" ];  then ok "$WIDGET_SRC";  else bad "$WIDGET_SRC";  fi
    say ""
    say "Installed:"
    if [ -f "$CHECKER" ]; then ok "checker: $CHECKER"; else bad "checker not installed ($CHECKER)"; fi
    if [ -f "$WIDGET" ];  then ok "widget:  $WIDGET";  else bad "widget not installed ($WIDGET)"; fi
    say ""
    say "Requirements:"
    check_wg
    if [ -d "$WIDGET_DIR" ]; then ok "widget directory exists"; else bad "widget directory missing ($WIDGET_DIR)"; fi
}

uninstall() {
    if ! confirm "Remove the checker and widget from this firewall?"; then
        say "Cancelled."
        return 0
    fi
    rm -f "$CHECKER" "$WIDGET"
    ok "removed $CHECKER and $WIDGET (backups, if any, were kept)"
    say "Remove the widget from the dashboard manually if it is still added."
}

menu() {
    while :; do
        say ""
        say "=============================================="
        say " WireGuard Stream Monitor - pfSense installer"
        say "=============================================="
        say " 1) Install checker and widget"
        say " 2) Install checker only"
        say " 3) Install widget only"
        say " 4) Test checker now"
        say " 5) Show status"
        say " 6) Uninstall"
        say " 0) Exit"
        say ""
        printf 'Choose an option: '
        read -r choice || { say ""; break; }

        case "$choice" in
            1)
                check_sources && { install_checker; install_widget; }
                say "Add the widget: Dashboard > + > WireGuard Streaming."
                pause ;;
            2)
                check_sources && install_checker
                pause ;;
            3)
                check_sources && install_widget
                pause ;;
            4)
                test_checker
                pause ;;
            5)
                show_status
                pause ;;
            6)
                uninstall
                pause ;;
            0|q|Q)
                break ;;
            *)
                say "Unknown option: $choice" ;;
        esac
    done
}

need_root
menu
say "Bye."
