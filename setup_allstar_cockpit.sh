#!/usr/bin/env bash
set -euo pipefail

APP_NAME="AllStar Cockpit"
APP_DIR="/var/www/html/allstar_cockpit"
CONFIG_FILE="$APP_DIR/config.ini"
CONFIG_EXAMPLE="$APP_DIR/config.ini.example"
APACHE_CONF_FILE="/etc/apache2/conf-available/allstar-cockpit-security.conf"
SUDOERS_FILE="/etc/sudoers.d/allstar-cockpit-read"
LOGROTATE_FILE="/etc/logrotate.d/allstar-cockpit"
WEB_USER="www-data"
WEB_GROUP="www-data"
AUTH_ACTION="normal"

log() {
    echo "[INFO] $*"
}

warn() {
    echo "[WARN] $*" >&2
}

fail() {
    echo "[ERROR] $*" >&2
    exit 1
}

usage() {
    cat <<EOF
Usage:
  sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh
  sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --set-admin-password
  sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --disable-auth
  sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --clean-config-layout

Normal setup/update preserves existing config and auth settings.
--set-admin-password enables web login and stores only a password hash.
--disable-auth sets login back to No Login / Normal and keeps the saved hash.
--clean-config-layout rewrites config.ini into the standard readable order while preserving values.
EOF
}

case "${1:-}" in
    --set-admin-password|--auth)
        AUTH_ACTION="set-password"
        shift
        ;;
    --disable-auth)
        AUTH_ACTION="disable-auth"
        shift
        ;;
    --clean-config-layout)
        AUTH_ACTION="clean-config-layout"
        shift
        ;;
    --help|-h)
        usage
        exit 0
        ;;
    "")
        ;;
    *)
        fail "Unknown option: ${1}. Run --help."
        ;;
esac

[[ "$#" -eq 0 ]] || fail "Too many arguments. Run --help."

require_root() {
    [[ "${EUID}" -eq 0 ]] || fail "Run this script as root."
}

require_app_dir() {
    [[ -d "$APP_DIR" ]] || fail "Application directory not found: $APP_DIR"
}

check_web_user() {
    id "$WEB_USER" >/dev/null 2>&1 || fail "Web user not found: $WEB_USER"
}

make_dirs() {
    mkdir -p \
        "$APP_DIR/app/Support" \
        "$APP_DIR/api" \
        "$APP_DIR/bin" \
        "$APP_DIR/data" \
        "$APP_DIR/data/cache" \
        "$APP_DIR/docs" \
        "$APP_DIR/logs" \
        "$APP_DIR/public/assets/css" \
        "$APP_DIR/public/assets/js" \
        "$APP_DIR/run"
}

create_config_if_missing() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        [[ -f "$CONFIG_EXAMPLE" ]] || fail "Missing config example: $CONFIG_EXAMPLE"
        cp "$CONFIG_EXAMPLE" "$CONFIG_FILE"
        warn "Created $CONFIG_FILE from example. Edit MYNODE before expecting live AllStar data."
    else
        :
    fi
}

config_has_key() {
    local key="$1"
    [[ -f "$CONFIG_FILE" ]] && grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$CONFIG_FILE"
}

append_config_key_if_missing() {
    local key="$1"
    local value="$2"
    if ! config_has_key "$key"; then
        printf '%s=%s\n' "$key" "$value" >> "$CONFIG_FILE"
    fi
}

ensure_auth_config_defaults() {
    create_config_if_missing

    if ! config_has_key "ALLSTAR_COCKPIT_AUTH_ENABLED"; then
        cat >> "$CONFIG_FILE" <<'EOF_AUTH_BLOCK'

; Optional web login. Disabled by default.
; Set/change with: sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --set-admin-password
; Disable with:    sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --disable-auth
EOF_AUTH_BLOCK
    fi

    append_config_key_if_missing "ALLSTAR_COCKPIT_AUTH_ENABLED" "0"
    append_config_key_if_missing "ALLSTAR_COCKPIT_ADMIN_USER" '"admin"'
    append_config_key_if_missing "ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH" '""'
}

clean_config_layout() {
    ensure_auth_config_defaults

    /usr/bin/php -r '
        $path = $argv[1];
        $cfg = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }

        $defaults = [
            "APP_NAME" => "AllStar Cockpit",
            "MYNODE" => "YOUR_ALLSTAR_NODE",
            "HIDE_NODES" => "",
            "ALLSTAR_COCKPIT_AUTH_ENABLED" => "0",
            "ALLSTAR_COCKPIT_ADMIN_USER" => "admin",
            "ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH" => "",
            "ASTERISK_BIN" => "/usr/sbin/asterisk",
            "USE_SUDO_HELPER" => "0",
            "HELPER_PATH" => "/var/www/html/allstar_cockpit/bin/allstar-cockpit-read.sh",
            "POLL_INTERVAL_SECONDS" => "1",
            "FAST_STATUS_ONLY" => "1",
            "HISTORY_LIMIT" => "250",
            "NODE_CACHE_TTL_SECONDS" => "86400",
            "CACHE_DIR" => "/var/www/html/allstar_cockpit/data/cache",
            "SHOW_OBSERVED_IPS" => "1",
            "ENABLE_EXTERNAL_LOOKUPS" => "0",
            "EXTERNAL_LOOKUP_CACHE_SECONDS" => "60",
            "REMOTE_DOWNSTREAM_LOOKUP_DEPTH" => "2",
            "REMOTE_DOWNSTREAM_LOOKUP_LIMIT" => "12",
            "HIDE_PRIVATE_NODE_RANGE" => "1",
            "VALID_DOWNSTREAM_ONLY" => "1",
            "SHOW_ECHOLINK_DOWNSTREAM" => "0",
            "DATA_DIR" => "/var/www/html/allstar_cockpit/data",
            "LOGS_DIR" => "/var/www/html/allstar_cockpit/logs",
            "RUN_DIR" => "/var/www/html/allstar_cockpit/run",
            "ACTIVITY_LOG" => "/var/www/html/allstar_cockpit/logs/activity.jsonl",
            "ERROR_LOG" => "/var/www/html/allstar_cockpit/logs/error.log",
            "SNAPSHOT_FILE" => "/var/www/html/allstar_cockpit/run/current_snapshot.json",
            "DOWNSTREAM_HISTORY_LOG" => "/var/www/html/allstar_cockpit/logs/downstream-history.jsonl",
            "DOWNSTREAM_CURRENT_FILE" => "/var/www/html/allstar_cockpit/run/downstream-current.json",
        ];

        $numeric = array_fill_keys([
            "ALLSTAR_COCKPIT_AUTH_ENABLED",
            "USE_SUDO_HELPER",
            "POLL_INTERVAL_SECONDS",
            "FAST_STATUS_ONLY",
            "HISTORY_LIMIT",
            "NODE_CACHE_TTL_SECONDS",
            "SHOW_OBSERVED_IPS",
            "ENABLE_EXTERNAL_LOOKUPS",
            "EXTERNAL_LOOKUP_CACHE_SECONDS",
            "REMOTE_DOWNSTREAM_LOOKUP_DEPTH",
            "REMOTE_DOWNSTREAM_LOOKUP_LIMIT",
            "HIDE_PRIVATE_NODE_RANGE",
            "VALID_DOWNSTREAM_ONLY",
            "SHOW_ECHOLINK_DOWNSTREAM",
        ], true);

        $used = [];
        $get = function (string $key) use ($cfg, $defaults): string {
            $value = array_key_exists($key, $cfg) ? (string)$cfg[$key] : (string)$defaults[$key];
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === "\"" && substr($value, -1) === "\"") {
                $value = substr($value, 1, -1);
            }
            return $value;
        };
        $quote = function (string $value): string {
            return "\"" . str_replace(["\\", "\""], ["\\\\", "\\\""], $value) . "\"";
        };
        $line = function (string $key) use (&$used, $get, $quote, $numeric): string {
            $used[$key] = true;
            $value = $get($key);
            if (isset($numeric[$key]) && preg_match("/^-?[0-9]+$/", $value)) {
                return $key . "=" . $value;
            }
            return $key . "=" . $quote($value);
        };

        $out = [];
        $out[] = "; AllStar Cockpit configuration";
        $out[] = "; Local file. Do not commit this file to GitHub.";
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Main settings users normally edit";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "";
        $out[] = $line("APP_NAME");
        $out[] = $line("MYNODE");
        $out[] = "";
        $out[] = "; Private/local nodes to hide. Leave blank if none.";
        $out[] = "; Example: HIDE_NODES=\"1957\"";
        $out[] = $line("HIDE_NODES");
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Login / security";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; 0 = No Login / Normal";
        $out[] = "; 1 = View Only until signed in";
        $out[] = "";
        $out[] = $line("ALLSTAR_COCKPIT_AUTH_ENABLED");
        $out[] = $line("ALLSTAR_COCKPIT_ADMIN_USER");
        $out[] = $line("ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH");
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Asterisk / helper";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "";
        $out[] = $line("ASTERISK_BIN");
        $out[] = $line("USE_SUDO_HELPER");
        $out[] = $line("HELPER_PATH");
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Dashboard refresh / history";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "";
        $out[] = $line("POLL_INTERVAL_SECONDS");
        $out[] = $line("FAST_STATUS_ONLY");
        $out[] = $line("HISTORY_LIMIT");
        $out[] = $line("NODE_CACHE_TTL_SECONDS");
        $out[] = $line("CACHE_DIR");
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Display / lookups";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "";
        $out[] = $line("SHOW_OBSERVED_IPS");
        $out[] = $line("ENABLE_EXTERNAL_LOOKUPS");
        $out[] = $line("EXTERNAL_LOOKUP_CACHE_SECONDS");
        $out[] = $line("REMOTE_DOWNSTREAM_LOOKUP_DEPTH");
        $out[] = $line("REMOTE_DOWNSTREAM_LOOKUP_LIMIT");
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Downstream filtering";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "";
        $out[] = $line("HIDE_PRIVATE_NODE_RANGE");
        $out[] = $line("VALID_DOWNSTREAM_ONLY");
        $out[] = $line("SHOW_ECHOLINK_DOWNSTREAM");
        $out[] = "";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "; Local project paths";
        $out[] = "; ------------------------------------------------------------";
        $out[] = "";
        $out[] = $line("DATA_DIR");
        $out[] = $line("LOGS_DIR");
        $out[] = $line("RUN_DIR");
        $out[] = $line("ACTIVITY_LOG");
        $out[] = $line("ERROR_LOG");
        $out[] = $line("SNAPSHOT_FILE");
        $out[] = $line("DOWNSTREAM_HISTORY_LOG");
        $out[] = $line("DOWNSTREAM_CURRENT_FILE");

        $unknown = [];
        foreach ($cfg as $key => $value) {
            if (!isset($used[$key])) {
                $unknown[$key] = (string)$value;
            }
        }
        if ($unknown) {
            $out[] = "";
            $out[] = "; ------------------------------------------------------------";
            $out[] = "; Other preserved settings";
            $out[] = "; ------------------------------------------------------------";
            $out[] = "";
            foreach ($unknown as $key => $value) {
                $value = trim($value);
                if (preg_match("/^-?[0-9]+$/", $value)) {
                    $out[] = $key . "=" . $value;
                } else {
                    if (strlen($value) >= 2 && $value[0] === "\"" && substr($value, -1) === "\"") {
                        $value = substr($value, 1, -1);
                    }
                    $out[] = $key . "=" . $quote($value);
                }
            }
        }

        $tmp = $path . ".tmp." . getmypid();
        file_put_contents($tmp, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
        rename($tmp, $path);
    ' "$CONFIG_FILE"

    set_permissions

    echo
    echo "[OK] config.ini layout cleaned. Existing values were preserved."
    echo "[OK] Main user settings are now grouped near the top."
}

config_get() {
    local key="$1"
    local default_value="${2:-}"
    /usr/bin/php -r '
        $path = $argv[1];
        $key = $argv[2];
        $default = $argv[3];
        $cfg = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
        $value = is_array($cfg) && array_key_exists($key, $cfg) ? (string)$cfg[$key] : $default;
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === "\"" && substr($value, -1) === "\"") {
            $value = substr($value, 1, -1);
        }
        echo $value;
    ' "$CONFIG_FILE" "$key" "$default_value"
}

config_set() {
    local key="$1"
    local value="$2"
    /usr/bin/php -r '
        $path = $argv[1];
        $key = $argv[2];
        $value = $argv[3];
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        if (!is_array($lines)) {
            $lines = [];
        }
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match("/^\\s*" . preg_quote($key, "/") . "\\s*=/", (string)$line)) {
                $lines[$i] = $key . "=" . $value;
                $found = true;
            }
        }
        if (!$found) {
            $lines[] = $key . "=" . $value;
        }
        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    ' "$CONFIG_FILE" "$key" "$value"
}

run_auth_password_setup() {
    ensure_auth_config_defaults

    echo
    echo "AllStar Cockpit Web Login Password Setup"
    echo "========================================="
    echo
    echo "This changes only the AllStar Cockpit web login password."
    echo "The password hash is created automatically."
    echo "The plain password is not stored."
    echo

    local password_one=""
    local password_two=""
    local current_user=""
    current_user="$(config_get ALLSTAR_COCKPIT_ADMIN_USER admin)"
    [[ "$current_user" != "" ]] || current_user="admin"

    read -r -s -p "New admin password: " password_one
    echo
    if [[ -z "$password_one" ]]; then
        fail "Password cannot be blank. Use --disable-auth to turn login off."
    fi

    read -r -s -p "Confirm admin password: " password_two
    echo
    if [[ "$password_one" != "$password_two" ]]; then
        fail "Passwords did not match. No changes were made."
    fi

    local hash=""
    hash="$(printf '%s' "$password_one" | /usr/bin/php -r '$password = stream_get_contents(STDIN); echo password_hash($password, PASSWORD_DEFAULT);')"
    [[ -n "$hash" ]] || fail "Failed to create password hash. No changes were made."

    config_set "ALLSTAR_COCKPIT_AUTH_ENABLED" "1"
    config_set "ALLSTAR_COCKPIT_ADMIN_USER" "\"$current_user\""
    config_set "ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH" "\"$hash\""

    unset password_one password_two

    set_permissions

    echo
    echo "[OK] Web login enabled."
    echo "[OK] Password hash saved in config.ini."
    echo
    echo "Next steps:"
    echo "1. Open /allstar_cockpit/public/ in your browser."
    echo "2. Sign in with the password you just set."
    echo
    echo "Notes:"
    echo "- The plain password was not stored."
    echo "- Normal setup/update will not change this password."
    echo "- To disable login later, run: sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --disable-auth"
}

run_auth_disable() {
    ensure_auth_config_defaults

    local current_user=""
    local current_hash=""
    current_user="$(config_get ALLSTAR_COCKPIT_ADMIN_USER admin)"
    current_hash="$(config_get ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH "")"
    [[ "$current_user" != "" ]] || current_user="admin"

    config_set "ALLSTAR_COCKPIT_AUTH_ENABLED" "0"
    config_set "ALLSTAR_COCKPIT_ADMIN_USER" "\"$current_user\""
    config_set "ALLSTAR_COCKPIT_ADMIN_PASSWORD_HASH" "\"$current_hash\""

    set_permissions

    echo
    echo "AllStar Cockpit Web Login Disable"
    echo "=================================="
    echo
    echo "[OK] Web login disabled."
    if [[ "$current_hash" != "" ]]; then
        echo "[OK] Existing password hash was kept."
    else
        echo "[OK] No password hash was set."
    fi
    echo
    echo "Next steps:"
    echo "1. Open /allstar_cockpit/public/ in your browser."
    echo "2. AllStar Cockpit should be back to No Login / Normal mode."
}

set_permissions() {
    chown -R root:root "$APP_DIR/app" "$APP_DIR/api" "$APP_DIR/bin" "$APP_DIR/docs" "$APP_DIR/public"
    find "$APP_DIR/app" "$APP_DIR/api" "$APP_DIR/bin" "$APP_DIR/docs" "$APP_DIR/public" -type d -exec chmod 0755 {} +
    find "$APP_DIR/app" "$APP_DIR/api" "$APP_DIR/docs" "$APP_DIR/public" -type f -exec chmod 0644 {} +

    chmod 0755 "$APP_DIR/bin/allstar-cockpit-read.sh" 2>/dev/null || true
    chmod 0755 "$APP_DIR/bin/allstar-cockpit-ami-status.php" 2>/dev/null || true
    chmod 0755 "$APP_DIR/setup_allstar_cockpit.sh"

    for file in "$APP_DIR/index.php" "$APP_DIR/login.php" "$APP_DIR/logout.php" "$APP_DIR/.htaccess" "$APP_DIR/README.md" "$APP_DIR/VERSION" "$CONFIG_EXAMPLE"; do
        if [[ -f "$file" ]]; then
            chown root:root "$file" 2>/dev/null || true
            chmod 0644 "$file" 2>/dev/null || true
        fi
    done

    if [[ -f "$CONFIG_FILE" ]]; then
        chown root:"$WEB_GROUP" "$CONFIG_FILE"
        chmod 0640 "$CONFIG_FILE"
    fi

    chown "$WEB_USER":"$WEB_GROUP" "$APP_DIR/data" "$APP_DIR/data/cache" "$APP_DIR/logs" "$APP_DIR/run"
    chmod 0775 "$APP_DIR/data" "$APP_DIR/data/cache" "$APP_DIR/logs" "$APP_DIR/run"

    for file in \
        "$APP_DIR/logs/activity.jsonl" \
        "$APP_DIR/logs/downstream-history.jsonl" \
        "$APP_DIR/logs/error.log"; do
        if [[ ! -f "$file" ]]; then
            : > "$file"
        fi
    done

    if [[ ! -f "$APP_DIR/run/current_snapshot.json" ]]; then
        cat > "$APP_DIR/run/current_snapshot.json" <<EOF_JSON
{
    "updated_at": "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)",
    "connections": [],
    "downstream_links": [],
    "connection_state": []
}
EOF_JSON
    fi

    if [[ ! -f "$APP_DIR/run/downstream-current.json" ]]; then
        cat > "$APP_DIR/run/downstream-current.json" <<EOF_JSON
{
    "updated_at": "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)",
    "nodes": []
}
EOF_JSON
    fi

    chown "$WEB_USER":"$WEB_GROUP" \
        "$APP_DIR/logs/activity.jsonl" \
        "$APP_DIR/logs/downstream-history.jsonl" \
        "$APP_DIR/logs/error.log" \
        "$APP_DIR/run/current_snapshot.json" \
        "$APP_DIR/run/downstream-current.json"

    chmod 0664 \
        "$APP_DIR/logs/activity.jsonl" \
        "$APP_DIR/logs/downstream-history.jsonl" \
        "$APP_DIR/logs/error.log" \
        "$APP_DIR/run/current_snapshot.json" \
        "$APP_DIR/run/downstream-current.json"
}

install_apache_security() {
    if [[ ! -d /etc/apache2/conf-available ]]; then
        warn "Apache conf-available not found. .htaccess and PHP auth guards still apply."
        return
    fi

    cat > "$APACHE_CONF_FILE" <<EOF_APACHE
# AllStar Cockpit browser protection
# Managed by setup_allstar_cockpit.sh

<Directory "$APP_DIR">
    Options -Indexes
    AllowOverride All
    Require all granted

    <FilesMatch "(^\\.|config\\.ini$|.*\\.bak.*$|.*\\.jsonl$|.*\\.log$|.*\\.state$|.*\\.pid$|.*\\.sh$|.*\\.md$)">
        Require all denied
    </FilesMatch>
</Directory>

<Directory "$APP_DIR/app">
    Require all denied
</Directory>

<Directory "$APP_DIR/bin">
    Require all denied
</Directory>

<Directory "$APP_DIR/docs">
    Require all denied
</Directory>

<Directory "$APP_DIR/data">
    Require all denied
</Directory>

<Directory "$APP_DIR/logs">
    Require all denied
</Directory>

<Directory "$APP_DIR/run">
    Require all denied
</Directory>
EOF_APACHE

    chmod 0644 "$APACHE_CONF_FILE"

    if command -v a2enconf >/dev/null 2>&1; then
        a2enconf allstar-cockpit-security >/dev/null || true
    fi

    if command -v apache2ctl >/dev/null 2>&1; then
        if ! apache2ctl configtest >/dev/null 2>&1; then
            apache2ctl configtest || true
            fail "Apache configtest failed after installing $APACHE_CONF_FILE"
        fi
    fi
}

reload_apache() {
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet apache2 2>/dev/null; then
        systemctl reload apache2 || systemctl restart apache2 || true
    elif command -v service >/dev/null 2>&1; then
        service apache2 reload >/dev/null 2>&1 || true
    fi
}

install_read_sudoers() {
    if [[ ! -d /etc/sudoers.d ]]; then
        warn "/etc/sudoers.d not found. Read helper sudoers was not installed."
        return
    fi

    command -v visudo >/dev/null 2>&1 || fail "visudo not found. Cannot safely install sudoers."

    local tmp="${SUDOERS_FILE}.tmp.$$"
    cat > "$tmp" <<EOF_SUDOERS
# AllStar Cockpit read-only Asterisk status helper
# Managed by setup_allstar_cockpit.sh
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh core_uptime
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh core_channels
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh iax_channels
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh rpt_nodes *
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh rpt_stats *
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh echolink_db
${WEB_USER} ALL=(root) NOPASSWD: ${APP_DIR}/bin/allstar-cockpit-read.sh ami_status *
EOF_SUDOERS

    chown root:root "$tmp"
    chmod 0440 "$tmp"

    if ! visudo -cf "$tmp" >/dev/null 2>&1; then
        visudo -cf "$tmp" || true
        rm -f "$tmp"
        fail "Generated sudoers failed validation: $tmp"
    fi

    mv "$tmp" "$SUDOERS_FILE"
    chown root:root "$SUDOERS_FILE"
    chmod 0440 "$SUDOERS_FILE"
}

install_logrotate() {
    if [[ ! -d /etc/logrotate.d ]]; then
        warn "/etc/logrotate.d not found. Logrotate config was not installed."
        return
    fi

    local have_logrotate=0
    if command -v logrotate >/dev/null 2>&1; then
        have_logrotate=1
    fi

    local tmp="${LOGROTATE_FILE}.tmp.$$"
    cat > "$tmp" <<EOF_LOGROTATE
# AllStar Cockpit logs and JSONL history
# Managed by setup_allstar_cockpit.sh
${APP_DIR}/logs/*.log ${APP_DIR}/logs/*.jsonl {
    daily
    maxsize 20M
    rotate 2
    missingok
    notifempty
    copytruncate
    nocompress
    su ${WEB_USER} ${WEB_GROUP}
    create 0664 ${WEB_USER} ${WEB_GROUP}
}
EOF_LOGROTATE

    chown root:root "$tmp"
    chmod 0644 "$tmp"

    if [[ "$have_logrotate" -eq 1 ]]; then
        if ! logrotate -d "$tmp" >/dev/null 2>&1; then
            logrotate -d "$tmp" || true
            rm -f "$tmp"
            fail "Generated logrotate config failed validation: $tmp"
        fi
    else
        warn "logrotate command not found. Installed logrotate config without validation."
    fi

    mv "$tmp" "$LOGROTATE_FILE"
    chown root:root "$LOGROTATE_FILE"
    chmod 0644 "$LOGROTATE_FILE"
}

check_syntax() {
    [[ -x /usr/bin/php ]] || fail "PHP not found at /usr/bin/php"

    local php_file=""
    while IFS= read -r -d '' php_file; do
        /usr/bin/php -l "$php_file" >/dev/null || fail "PHP syntax failed: ${php_file#$APP_DIR/}"
    done < <(
        find "$APP_DIR/app" "$APP_DIR/api" "$APP_DIR/bin" "$APP_DIR/public" -type f -name '*.php' -print0
        printf '%s\0' "$APP_DIR/index.php" "$APP_DIR/login.php" "$APP_DIR/logout.php"
    )

    bash -n "$APP_DIR/bin/allstar-cockpit-read.sh" || fail "Shell syntax failed: bin/allstar-cockpit-read.sh"
    bash -n "$APP_DIR/setup_allstar_cockpit.sh" || fail "Shell syntax failed: setup_allstar_cockpit.sh"

    if command -v node >/dev/null 2>&1; then
        node --check "$APP_DIR/public/assets/js/app.js" >/dev/null || fail "JS syntax failed: public/assets/js/app.js"
    fi
}

show_summary() {
    echo
    echo "AllStar Cockpit setup complete."
    echo "Open:         /allstar_cockpit/public/"
    echo "Config:       $CONFIG_FILE"
    echo "Sudoers:      $SUDOERS_FILE"
    echo "Logrotate:    $LOGROTATE_FILE"
    echo "Auth:         Optional. Run --help for auth commands."
}

main() {
    require_root
    require_app_dir
    check_web_user
    make_dirs

    if [[ "$AUTH_ACTION" == "set-password" ]]; then
        run_auth_password_setup
        exit 0
    fi

    if [[ "$AUTH_ACTION" == "disable-auth" ]]; then
        run_auth_disable
        exit 0
    fi

    if [[ "$AUTH_ACTION" == "clean-config-layout" ]]; then
        clean_config_layout
        exit 0
    fi

    ensure_auth_config_defaults
    set_permissions
    check_syntax
    install_read_sudoers
    install_apache_security
    install_logrotate
    reload_apache
    show_summary
}

main "$@"
