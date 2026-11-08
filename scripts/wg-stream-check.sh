#!/bin/sh
#
# wg-stream-check.sh - report which WireGuard peers are actively passing traffic.
#
# Usage: wg-stream-check.sh [min_window_seconds] [threshold_kbps] [stale_seconds]
#   min_window_seconds  minimum time between counter samples (default 3). This script
#                       does not sleep: each call compares the counters with the last
#                       sample saved in STATE, so the rate covers the time between calls.
#                       A call sooner than this reuses the previous rate.
#   threshold_kbps      combined rx+tx rate at/above which a peer is STREAMING (default 100)
#   stale_seconds       handshake age after which a peer counts as offline (default 180)
#
# The first call after a restart has no earlier sample, so its rates are 0 and the
# widget's confirmation failsafe smooths that over.
#
# Output: a JSON array with one object per peer.
# Requires: wg (wireguard-tools, shipped with the pfSense WireGuard package), awk, mktemp.

VERSION="1.1.0"

set -eu

WINDOW=${1:-3}
THRESHOLD=${2:-100}
STALE=${3:-180}
STATE=${WG_STREAM_STATE:-/var/run/wg-stream-check.state}

# Arguments are interpolated into awk, so refuse anything that is not a plain integer.
case "$WINDOW$THRESHOLD$STALE" in
  *[!0-9]*|'') echo '{"error":"arguments must be non-negative integers"}'; exit 1 ;;
esac
if [ "$WINDOW" -lt 1 ]; then
  echo '{"error":"min_window_seconds must be at least 1"}'
  exit 1
fi

DUMP=$(mktemp)
NEWSTATE="$STATE.tmp"
trap 'rm -f "$DUMP" "$NEWSTATE"' EXIT

if ! wg show all dump > "$DUMP" 2>/dev/null; then
  echo '{"error":"wg show failed - is WireGuard running?"}'
  exit 1
fi
NOW=$(date +%s)

# The state file always starts with a header line, so it is never empty. That keeps the
# awk "first file / second file" test correct.
if [ ! -f "$STATE" ]; then
  echo '# wg-stream-check state' > "$STATE"
fi

# Dump format: interface lines have 5 tab-separated fields, peer lines have 9:
#   iface pubkey psk endpoint allowed-ips latest-handshake rx-bytes tx-bytes keepalive
# State format (one line per peer): key rx tx sample_time kbps, where key is iface|pubkey.
awk -F'\t' -v now="$NOW" -v minwin="$WINDOW" -v thr="$THRESHOLD" -v stale="$STALE" -v nst="$NEWSTATE" '
BEGIN { print "# wg-stream-check state" > nst }
FNR == NR {
  if (split($0, a, " ") == 5) { k = a[1]; srx[k] = a[2] + 0; stx[k] = a[3] + 0; st[k] = a[4] + 0; skb[k] = a[5] + 0 }
  next
}
NF != 9 { next }
{
  key = $1 "|" $2
  rx = $7 + 0
  tx = $8 + 0
  hs = $6 + 0

  if (key in st) {
    dt = now - st[key]
    if (dt < minwin) {
      # Called too soon after the last sample: keep the old sample and rate
      kbps = skb[key]; brx = srx[key]; btx = stx[key]; bt = st[key]
    } else {
      drx = rx - srx[key]; if (drx < 0) drx = 0   # counters reset when a peer reconnects
      dtx = tx - stx[key]; if (dtx < 0) dtx = 0
      kbps = (drx + dtx) * 8 / 1000 / dt
      brx = rx; btx = tx; bt = now
    }
  } else {
    # No earlier sample for this peer
    kbps = 0; brx = rx; btx = tx; bt = now
  }
  printf "%s %.0f %.0f %d %.3f\n", key, brx, btx, bt, kbps > nst

  if (hs == 0)          status = "never-connected"
  else if (now - hs > stale) status = "offline"
  else if (kbps >= thr) status = "streaming"
  else                  status = "connected-idle"
  age = (hs == 0) ? -1 : now - hs

  out[n++] = sprintf("{\"interface\":\"%s\",\"peer\":\"%s\",\"endpoint\":\"%s\",\"allowed_ips\":\"%s\",\"handshake_age\":%d,\"kbps\":%.1f,\"status\":\"%s\"}", $1, $2, $4, $5, age, kbps, status)
}
END {
  printf "["
  for (i = 0; i < n; i++) printf "%s%s", (i ? "," : ""), out[i]
  print "]"
}
' "$STATE" "$DUMP"

# Keep only the peers that exist now (the awk above wrote them all to NEWSTATE)
mv "$NEWSTATE" "$STATE"
