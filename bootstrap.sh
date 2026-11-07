#!/bin/sh
#
# bootstrap.sh - download the WireGuard Stream Monitor and start the install menu.
#
# Run on the pfSense firewall as root:
#   curl -sL https://raw.githubusercontent.com/simonleroux/pfsense_widget/main/bootstrap.sh | sh
#
# Overrides (for testing):
#   WG_TARBALL_URL  archive to download (default: GitHub main branch)
#   WG_DEST         where to unpack it   (default: /root/pfsense_widget)

set -eu

URL=${WG_TARBALL_URL:-https://github.com/simonleroux/pfsense_widget/archive/refs/heads/main.tar.gz}
DEST=${WG_DEST:-/root/pfsense_widget}

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "Downloading $URL"
if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$URL" -o "$TMP/repo.tar.gz"
else
    fetch -o "$TMP/repo.tar.gz" "$URL"
fi

mkdir -p "$DEST"
tar -xzf "$TMP/repo.tar.gz" -C "$DEST" --strip-components=1
echo "Unpacked to $DEST"

# This script was piped in, so stdin is the download. Reattach the menu to the terminal.
if (: </dev/tty) 2>/dev/null; then
    sh "$DEST/install.sh" </dev/tty
else
    sh "$DEST/install.sh"
fi
