#!/usr/bin/env bash
set -euo pipefail

ASTERISK_BIN="${ASTERISK_BIN:-/usr/sbin/asterisk}"
COMMAND="${1:-}"
NODE="${2:-}"

fail() {
    echo "[ERROR] $*" >&2
    exit 1
}

[[ -x "$ASTERISK_BIN" ]] || fail "Asterisk binary not executable: $ASTERISK_BIN"

case "$COMMAND" in
    core_uptime)
        exec "$ASTERISK_BIN" -rx "core show uptime"
        ;;
    core_channels)
        exec "$ASTERISK_BIN" -rx "core show channels concise"
        ;;
    iax_channels)
        exec "$ASTERISK_BIN" -rx "iax2 show channels"
        ;;
    rpt_nodes)
        [[ "$NODE" =~ ^[0-9]+$ ]] || fail "rpt_nodes requires numeric node"
        exec "$ASTERISK_BIN" -rx "rpt nodes $NODE"
        ;;
    rpt_stats)
        [[ "$NODE" =~ ^[0-9]+$ ]] || fail "rpt_stats requires numeric node"
        exec "$ASTERISK_BIN" -rx "rpt stats $NODE"
        ;;
    echolink_db)
        exec "$ASTERISK_BIN" -rx "echolink dbdump"
        ;;
    ami_status)
        [[ "$NODE" =~ ^[0-9]+$ ]] || fail "ami_status requires numeric node"
        exec /usr/bin/php /var/www/html/allstar_cockpit/bin/allstar-cockpit-ami-status.php "$NODE"
        ;;
    *)
        fail "Unknown safe command: $COMMAND"
        ;;
esac
