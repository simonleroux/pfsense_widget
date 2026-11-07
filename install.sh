#!/bin/sh
#
# install.sh - pfSense menu for the WireGuard Stream Monitor. Run on the firewall as root:
#   curl -sL https://raw.githubusercontent.com/simonleroux/pfsense_widget/main/bootstrap.sh | sudo sh
#
# Installs, updates, checks and removes the checker and the dashboard widget.
# Widget settings (threshold, sample time, watch list) are kept across updates.

VERSION="1.0.0"

REPO_RAW=${WG_REPO_RAW:-https://raw.githubusercontent.com/simonleroux/pfsense_widget/main}
REPO_TARBALL=${WG_TARBALL_URL:-https://github.com/simonleroux/pfsense_widget/archive/refs/heads/main.tar.gz}

SRC_DIR=$(cd "$(dirname "$0")" && pwd)
CHECKER_SRC="$SRC_DIR/scripts/wg-stream-check.sh"
WIDGET_SRC="$SRC_DIR/widget/wg_stream_monitor.widget.php"
INCLUDE_SRC="$SRC_DIR/widget/include/wg_stream_monitor.inc"

BIN_DIR=${WG_STREAM_BIN_DIR:-/usr/local/bin}
WIDGET_DIR=${WG_STREAM_WIDGET_DIR:-/usr/local/www/widgets/widgets}
INCLUDE_DIR=${WG_STREAM_INCLUDE_DIR:-/usr/local/www/widgets/include}
CHECKER="$BIN_DIR/wg-stream-check.sh"
WIDGET="$WIDGET_DIR/wg_stream_monitor.widget.php"
INCLUDE="$INCLUDE_DIR/wg_stream_monitor.inc"

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
        warn "run this as root (for example, with: sudo sh, or from the pfSense console shell)."
        exit 1
    fi
}

# ---- Versions -------------------------------------------------------------

# VERSION from an installed file, or a status word
installed_version() {
    if [ -f "$1" ]; then
        v=$(sed -n 's/.*VERSION="\([^"]*\)".*/\1/p' "$1" | head -n 1)
        echo "${v:-unknown (older install)}"
    else
        echo "not installed"
    fi
}

# Downloads URL to DEST. Uses curl if present, otherwise fetch (pfSense base system).
download() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --max-time 20 "$1" -o "$2"
    else
        fetch -q -T 20 -o "$2" "$1"
    fi
}

# VERSION of a file in the repo on GitHub. The timestamp in the URL stops GitHub's cache
# from returning an old copy.
server_version() {
    tmp=$(mktemp) || { echo "unavailable"; return; }
    v=""
    if download "$REPO_RAW/$1?t=$(date +%s)" "$tmp" 2>/dev/null; then
        v=$(sed -n 's/.*VERSION="\([^"]*\)".*/\1/p' "$tmp" | head -n 1)
    fi
    rm -f "$tmp"
    echo "${v:-unavailable}"
}

# Says whether an installed version matches the server. $1 installed, $2 server
version_status() {
    case "$1" in
        "not installed") echo "not installed" ;;
        "$2") echo "up to date" ;;
        *)
            if [ "$2" = "unavailable" ]; then
                echo "could not reach server"
            else
                echo "UPDATE AVAILABLE, server $2 (use option 2)"
            fi
            ;;
    esac
}

# ---- Install pieces -------------------------------------------------------

check_sources() {
    missing=0
    [ -f "$CHECKER_SRC" ] || { warn "missing $CHECKER_SRC"; missing=1; }
    [ -f "$WIDGET_SRC" ]  || { warn "missing $WIDGET_SRC"; missing=1; }
    [ -f "$INCLUDE_SRC" ] || { warn "missing $INCLUDE_SRC"; missing=1; }
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

# Copies the settings lines (sample time, threshold, stale time, watch list) from the
# installed widget into the new copy, so updates keep your tuning.
carry_widget_settings() {
    # $1 installed widget, $2 new widget file (rewritten in place)
    cat > "$TMP_CARRY_AWK" <<'AWK'
function key_of(line,   k) { k = line; sub(/[ \t]*=.*/, "", k); sub(/^\$/, "", k); return k }
function is_setting(k) { return (k == "sample_seconds" || k == "threshold_kbps" || k == "stale_seconds" || k == "watch_peers") }
FNR == NR {
    if ($0 ~ /^\$[a-z_]+[ \t]*=/) { k = key_of($0); if (is_setting(k)) saved[k] = $0 }
    next
}
{
    if ($0 ~ /^\$[a-z_]+[ \t]*=/) {
        k = key_of($0)
        if (is_setting(k) && (k in saved)) { print saved[k]; next }
    }
    print
}
AWK
    awk -f "$TMP_CARRY_AWK" "$1" "$2" > "$2.carried" && mv "$2.carried" "$2"
}

install_widget() {
    [ -f "$WIDGET_SRC" ] || { warn "missing $WIDGET_SRC"; return 1; }
    if [ ! -d "$WIDGET_DIR" ]; then
        warn "$WIDGET_DIR not found. Is this a pfSense firewall with the web GUI installed?"
        return 1
    fi
    TMP_CARRY_AWK=$(mktemp) || return 1
    cp "$WIDGET_SRC" "$WIDGET.new" || { rm -f "$TMP_CARRY_AWK"; return 1; }
    if [ -f "$WIDGET" ]; then
        cp "$WIDGET" "$WIDGET.bak" && say "  Backed up existing widget to $WIDGET.bak"
        carry_widget_settings "$WIDGET" "$WIDGET.new"
        say "  Kept your widget settings"
    fi
    rm -f "$TMP_CARRY_AWK"
    if mv "$WIDGET.new" "$WIDGET" && chmod 644 "$WIDGET"; then
        ok "widget installed at $WIDGET"
    else
        warn "failed to install widget to $WIDGET"
        return 1
    fi

    # The include file sets the dashboard title (pfSense reads it from widgets/include/)
    if [ -d "$INCLUDE_DIR" ] && cp "$INCLUDE_SRC" "$INCLUDE" && chmod 644 "$INCLUDE"; then
        ok "dashboard title installed at $INCLUDE"
    else
        warn "failed to install $INCLUDE (the dashboard title will be 'Wg Stream Monitor')"
        return 1
    fi
}

install_all() {
    check_sources || return 1
    install_checker && install_widget
    say ""
    say "Add the widget: Dashboard > + > WireGuard Streaming."
}

# ---- Status ---------------------------------------------------------------

check_wg() {
    if command -v wg >/dev/null 2>&1; then
        ok "wg found at $(command -v wg)"
    else
        bad "wg not found. Install the WireGuard package in pfSense (System > Package Manager)."
    fi
}

test_checker() {
    if [ ! -x "$CHECKER" ]; then
        warn "checker is not installed yet. Install it first (option 1)."
        return 1
    fi
    say "Running: $CHECKER 3 100 180  (takes about 3 seconds)"
    "$CHECKER" 3 100 180
    say ""
}

show_status() {
    say "Installed:"
    if [ -f "$CHECKER" ]; then ok "checker: $CHECKER"; else bad "checker not installed ($CHECKER)"; fi
    if [ -f "$WIDGET" ];  then ok "widget:  $WIDGET";  else bad "widget not installed ($WIDGET)"; fi
    say ""
    say "Requirements:"
    check_wg
    if [ -d "$WIDGET_DIR" ]; then ok "widget directory exists"; else bad "widget directory missing ($WIDGET_DIR)"; fi
    if [ -x "$CHECKER" ]; then
        say ""
        test_checker
    fi
}

# ---- Update and uninstall -------------------------------------------------

# Downloads the repo tarball and copies it over this project folder
download_project() {
    TMP=$(mktemp -d) || { warn "cannot create a temp directory"; return 1; }
    if ! download "$REPO_TARBALL" "$TMP/repo.tar.gz"; then
        warn "download failed: $REPO_TARBALL"
        rm -rf "$TMP"
        return 1
    fi
    mkdir "$TMP/new" && tar -xzf "$TMP/repo.tar.gz" -C "$TMP/new" --strip-components=1 || {
        warn "could not unpack the download"
        rm -rf "$TMP"
        return 1
    }
    if [ ! -f "$TMP/new/install.sh" ] || [ ! -f "$TMP/new/scripts/wg-stream-check.sh" ] || [ ! -f "$TMP/new/widget/wg_stream_monitor.widget.php" ]; then
        warn "the download is missing expected files; nothing was changed"
        rm -rf "$TMP"
        return 1
    fi
    if ! cp -R "$TMP/new/." "$SRC_DIR/"; then
        warn "could not copy the new files into $SRC_DIR"
        rm -rf "$TMP"
        return 1
    fi
    rm -rf "$TMP"
    ok "project files updated in $SRC_DIR"
}

update_installed() {
    if [ ! -f "$CHECKER" ] && [ ! -f "$WIDGET" ]; then
        say "Nothing installed yet. Choose option 1 first."
        return 0
    fi
    say "This downloads the latest version from GitHub into $SRC_DIR (overwriting local"
    say "changes there) and reinstalls what is installed. Widget settings are kept."
    if ! confirm "Continue?"; then
        say "Cancelled."
        return 0
    fi
    download_project || return 1
    if [ -f "$CHECKER" ]; then install_checker; fi
    if [ -f "$WIDGET" ]; then install_widget; fi
    say ""
    say "Update finished. Restarting the menu with the new installer."
    pause
    # Replace this process with the updated menu, so the old script is no longer running.
    exec sh "$SRC_DIR/install.sh"
}

uninstall() {
    if ! confirm "Remove the checker and widget from this firewall?"; then
        say "Cancelled."
        return 0
    fi
    rm -f "$CHECKER" "$WIDGET" "$INCLUDE"
    ok "removed $CHECKER, $WIDGET and $INCLUDE (backups, if any, were kept)"
    say "Remove the widget from the dashboard manually if it is still added."
}

# ---- Menu -----------------------------------------------------------------

# One header line. $1 label, $2 installed version, $3 server version
component_line() {
    if [ "$2" = "not installed" ]; then
        printf '  %-15s not installed\n' "$1"
    else
        printf '  %-15s %s (%s)\n' "$1" "$2" "$(version_status "$2" "$3")"
    fi
}

show_menu() {
    menu_server=$(server_version install.sh)
    checker_installed=$(installed_version "$CHECKER")
    widget_installed=$(installed_version "$WIDGET")
    checker_server=$(server_version scripts/wg-stream-check.sh)
    widget_server=$(server_version widget/wg_stream_monitor.widget.php)
    say ""
    say "====================================="
    say "  WireGuard Stream Monitor (pfSense)"
    say "====================================="
    component_line "Menu version:" "$VERSION" "$menu_server"
    component_line "Checker:" "$checker_installed" "$checker_server"
    component_line "Widget:" "$widget_installed" "$widget_server"
    say "====================================="
    say "1) Install checker and widget"
    say "2) Update installed (keeps your widget settings)"
    say "3) Check status"
    say "4) Uninstall"
    say "0) Exit"
    say "====================================="
}

menu() {
    while :; do
        show_menu
        printf 'Select an option [0-4]: '
        read -r choice || { say ""; break; }
        say ""

        case "$choice" in
            1)
                install_all
                pause ;;
            2)
                update_installed
                pause ;;
            3)
                show_status
                pause ;;
            4)
                uninstall
                pause ;;
            0|q|Q)
                break ;;
            *)
                say "Invalid option." ;;
        esac
    done
}

need_root
menu
say "Bye."
