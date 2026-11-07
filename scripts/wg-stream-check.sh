#!/bin/sh
#
# wg-stream-check.sh - report which WireGuard peers are actively passing traffic.
#
# Usage: wg-stream-check.sh [sample_seconds] [threshold_kbps] [stale_seconds]
#   sample_seconds  time between the two counter samples          (default 3)
#   threshold_kbps  combined rx+tx rate at/above which a peer is STREAMING (default 100)
#   stale_seconds   handshake age after which a peer counts as offline   (default 180)
#
# Output: a JSON array with one object per peer.
# Requires: wg (wireguard-tools, shipped with the pfSense WireGuard package), awk, mktemp, sleep.

set -eu

SAMPLE=${1:-3}
THRESHOLD=${2:-100}
STALE=${3:-180}

# Arguments are interpolated into awk, so refuse anything that is not a plain integer.
case "$SAMPLE$THRESHOLD$STALE" in
  *[!0-9]*|'') echo '{"error":"arguments must be non-negative integers"}'; exit 1 ;;
esac
if [ "$SAMPLE" -lt 1 ]; then
  echo '{"error":"sample_seconds must be at least 1"}'
  exit 1
fi

OUT=$(mktemp)
trap 'rm -f "$OUT"' EXIT

# Two snapshots of `wg show all dump`, separated by a marker line, so one awk pass can diff them.
# Dump format: interface lines have 5 tab-separated fields, peer lines have 9:
#   iface pubkey psk endpoint allowed-ips latest-handshake rx-bytes tx-bytes keepalive
if ! wg show all dump >/dev/null 2>&1; then
  echo '{"error":"wg show failed - is WireGuard running?"}'
  exit 1
fi
echo '#1' > "$OUT"
wg show all dump >> "$OUT"
sleep "$SAMPLE"
echo '#2' >> "$OUT"
wg show all dump >> "$OUT"
NOW=$(date +%s)

awk -F'\t' -v interval="$SAMPLE" -v thr="$THRESHOLD" -v stale="$STALE" -v now="$NOW" '
/^#1$/ { pass = 1; next }
/^#2$/ { pass = 2; next }
NF != 9 { next }
{
  key = $1 "|" $2
  if (pass == 1) { rx1[key] = $7 + 0; tx1[key] = $8 + 0; next }

  base_rx = (key in rx1) ? rx1[key] : $7 + 0
  base_tx = (key in tx1) ? tx1[key] : $8 + 0
  drx = $7 + 0 - base_rx; if (drx < 0) drx = 0   # counters reset when a peer reconnects
  dtx = $8 + 0 - base_tx; if (dtx < 0) dtx = 0
  kbps = (drx + dtx) * 8 / 1000 / interval

  hs = $6 + 0
  age = (hs == 0) ? -1 : now - hs
  if (hs == 0)            status = "never-connected"
  else if (age > stale)   status = "offline"
  else if (kbps >= thr)   status = "streaming"
  else                    status = "connected-idle"

  out[n++] = sprintf("{\"interface\":\"%s\",\"peer\":\"%s\",\"endpoint\":\"%s\",\"allowed_ips\":\"%s\",\"handshake_age\":%d,\"kbps\":%.1f,\"status\":\"%s\"}", $1, $2, $4, $5, age, kbps, status)
}
END {
  printf "["
  for (i = 0; i < n; i++) printf "%s%s", (i ? "," : ""), out[i]
  print "]"
}
' "$OUT"
