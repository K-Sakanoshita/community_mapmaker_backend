#!/usr/bin/env bash
set -euo pipefail

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH= cd -- "$script_dir/.." && pwd)
compose_file="$project_root/docker/local/compose.yml"
compose_lan_file="$project_root/docker/local/compose.lan.yml"
compose_tailscale_file="$project_root/docker/local/compose.tailscale.yml"
compose_hostname_file="$project_root/docker/local/compose.hostname.yml"
compose_project=${CMM_TEST_PROJECT_NAME:-community-mapmaker-backend-test}
web_port=${CMM_TEST_WEB_PORT:-18080}
https_port=${CMM_TEST_HTTPS_PORT:-18443}
db_port=${CMM_TEST_DB_PORT:-13306}
compose_access_file=${CMM_TEST_ACCESS_COMPOSE_FILE:-${TMPDIR:-/tmp}/${compose_project}-network-access.yml}
ca_file="$project_root/docker/local/cmm-local-ca.crt"

detect_lan_host() {
    local candidate=''
    if command -v ip >/dev/null 2>&1; then
        candidate=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit } }')
    fi
    if [[ -z "$candidate" ]] && command -v hostname >/dev/null 2>&1; then
        candidate=$(hostname -I 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/ && $i !~ /^127\./) { print $i; exit } }')
    fi
    printf '%s' "$candidate"
}

detect_tailscale_host() {
    local candidate=''
    if command -v tailscale >/dev/null 2>&1; then
        candidate=$(tailscale ip -4 2>/dev/null | head -n 1 || true)
    fi
    printf '%s' "$candidate"
}

detect_tailscale_dns() {
    local candidate=''
    if command -v tailscale >/dev/null 2>&1; then
        candidate=$(tailscale status --json 2>/dev/null | awk -F'"' '/"DNSName":/ { print $4; exit }' || true)
    fi
    printf '%s' "${candidate%.}"
}

detect_hostname_ipv4() {
    local name=$1 candidate=''
    if [[ -n "$name" ]] && command -v getent >/dev/null 2>&1; then
        candidate=$(getent ahostsv4 "$name" 2>/dev/null | awk '$1 ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/ && $1 !~ /^127\./ { print $1; exit }')
    fi
    printf '%s' "$candidate"
}

valid_ipv4() {
    local value=$1 a b c d
    [[ "$value" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    IFS=. read -r a b c d <<< "$value"
    ((10#$a >= 1 && 10#$a <= 255 && 10#$b <= 255 && 10#$c <= 255 && 10#$d <= 255))
}

lan_host=${CMM_TEST_LAN_HOST:-$(detect_lan_host)}
lan_host=${lan_host:-127.0.0.1}
tailscale_host=${CMM_TEST_TAILSCALE_HOST:-$(detect_tailscale_host)}
host_name=${CMM_TEST_HOSTNAME:-$(hostname -s 2>/dev/null || true)}
host_ipv4=${CMM_TEST_HOST_IPV4:-$(detect_hostname_ipv4 "$host_name")}
tailscale_dns=${CMM_TEST_TAILSCALE_DNS:-$(detect_tailscale_dns)}
tls_volume=${CMM_TEST_TLS_VOLUME:-${compose_project}-tls}

export CMM_TEST_WEB_PORT="$web_port"
export CMM_TEST_HTTPS_PORT="$https_port"
export CMM_TEST_DB_PORT="$db_port"
export CMM_TEST_LAN_HOST="$lan_host"
export CMM_TEST_TAILSCALE_HOST="$tailscale_host"
export CMM_TEST_HOSTNAME="$host_name"
export CMM_TEST_HOST_IPV4="$host_ipv4"
export CMM_TEST_TAILSCALE_DNS="$tailscale_dns"
export CMM_TEST_TLS_VOLUME="$tls_volume"

write_access_override() {
    cat > "$compose_access_file" <<EOF
services:
  web:
    ports: !override
      - "0.0.0.0:${web_port}:8080"
  https-gateway:
    ports: !override
      - "0.0.0.0:${https_port}:443"
  database:
    ports: !override
      - "127.0.0.1:${db_port}:3306"
EOF
}

compose() {
    write_access_override
    local files=(--file "$compose_file" --file "$compose_lan_file")
    if [[ -n "$tailscale_host" && "$tailscale_host" != "$lan_host" ]]; then
        files+=(--file "$compose_tailscale_file")
    fi
    if [[ -n "$host_ipv4" && "$host_ipv4" != 127.* && "$host_ipv4" != "$lan_host" && "$host_ipv4" != "$tailscale_host" ]]; then
        files+=(--file "$compose_hostname_file")
    fi
    # Apply last so host-port bindings cannot be narrowed by the LAN/VPN overlays.
    # Web/HTTPS listen on every host interface; MariaDB remains loopback-only.
    files+=(--file "$compose_access_file")
    docker compose --project-name "$compose_project" "${files[@]}" "$@"
}

usage() {
    cat <<'EOF'
Usage: ./scripts/local-test-servers.sh COMMAND

Commands:
  start          Build and start local/LAN/VPN HTTP, LAN HTTPS, and MariaDB servers
  restart        Restart all servers without deleting DB data
  status         Show container, HTTP, and HTTPS status
  test           Verify the Web, public API, and admin-only APIs
  migrate        Apply pending local database migrations
  certificate    Export the public local CA certificate for LAN clients
  logs [SERVICE] Follow logs (web, https-gateway, or database)
  stop           Stop all servers; DB data is preserved
  reset --yes    Delete the local DB volume, recreate it, and start fresh
  help           Show this help

Optional environment variables:
  CMM_TEST_WEB_PORT       Local/LAN/VPN HTTP port (default: 18080)
  CMM_TEST_HTTPS_PORT     Host LAN HTTPS port (default: 18443)
  CMM_TEST_DB_PORT        Host MariaDB port, loopback-only (default: 13306)
  CMM_TEST_LAN_HOST       LAN IPv4 for binding and TLS (auto-detected)
  CMM_TEST_TAILSCALE_HOST Tailscale IPv4 for display/tests (auto-detected)
  CMM_TEST_HOSTNAME       Local hostname allowed by CORS (auto-detected)
  CMM_TEST_HOST_IPV4      Local hostname IPv4 for binding (auto-detected)
  CMM_TEST_TAILSCALE_DNS  Tailscale DNS name allowed by CORS (auto-detected)
  CMM_TEST_TLS_VOLUME     Docker volume that preserves the local CA
  CMM_TEST_PROJECT_NAME   Docker Compose project name
  CMM_TEST_ACCESS_COMPOSE_FILE Generated network-binding override path
EOF
}

require_tools() {
    command -v docker >/dev/null 2>&1 || { echo 'docker is required.' >&2; exit 1; }
    docker compose version >/dev/null 2>&1 || { echo 'Docker Compose v2 is required.' >&2; exit 1; }
    command -v curl >/dev/null 2>&1 || { echo 'curl is required.' >&2; exit 1; }
}

fetch_schema() {
    local response
    response=$(curl "$@") || return 1
    [[ "$response" == '{"apps":['* ]]
}

wait_for_http() {
    local url="http://127.0.0.1:$web_port/api/activity-schema.php"
    local attempt
    for attempt in $(seq 1 30); do
        if fetch_schema --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Web readiness check failed: $url" >&2
    compose logs --tail=80 web >&2
    return 1
}

wait_for_lan_http() {
    local url="http://$lan_host:$web_port/api/activity-schema.php"
    local attempt
    for attempt in $(seq 1 30); do
        if fetch_schema --noproxy '*' --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "LAN HTTP readiness check failed: $url" >&2
    compose logs --tail=80 web >&2
    return 1
}

wait_for_tailscale_http() {
    [[ -n "$tailscale_host" ]] || return 0
    local url="http://$tailscale_host:$web_port/api/activity-schema.php"
    local attempt
    for attempt in $(seq 1 30); do
        if fetch_schema --noproxy '*' --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Tailscale HTTP readiness check failed: $url" >&2
    compose logs --tail=80 web >&2
    return 1
}

wait_for_hostname_http() {
    [[ -n "$host_name" && -n "$host_ipv4" && "$host_ipv4" != 127.* ]] || return 0
    local url="http://$host_name:$web_port/api/activity-schema.php"
    local attempt
    for attempt in $(seq 1 30); do
        if fetch_schema --noproxy '*' --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Hostname HTTP readiness check failed: $url (resolved IPv4: $host_ipv4)" >&2
    compose logs --tail=80 web >&2
    return 1
}

wait_for_https() {
    local url="https://$lan_host:$https_port/api/activity-schema.php"
    local attempt
    for attempt in $(seq 1 30); do
        if fetch_schema --noproxy '*' --insecure --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "HTTPS readiness check failed: $url" >&2
    compose logs --tail=80 https-gateway >&2
    return 1
}

export_ca() {
    compose cp https-gateway:/data/caddy/pki/authorities/local/root.crt "$ca_file"
    chmod 0644 "$ca_file"
}

prepare_https() {
    if ! valid_ipv4 "$lan_host" || [[ "$lan_host" == 127.* ]]; then
        echo 'A non-loopback IPv4 address is required for LAN HTTPS.' >&2
        echo 'Set it explicitly, for example: CMM_TEST_LAN_HOST=192.168.1.10 ./scripts/local-test-servers.sh start' >&2
        exit 2
    fi
    docker volume create "$tls_volume" >/dev/null
}

print_access() {
    cat <<EOF
Web:      http://127.0.0.1:$web_port/admin/
API:      http://127.0.0.1:$web_port/api/activity-schema.php
LAN HTTP: http://$lan_host:$web_port/admin/
EOF
    if [[ -n "$host_name" && -n "$host_ipv4" && "$host_ipv4" != 127.* ]]; then
        printf 'Host HTTP: http://%s:%s/admin/\n' "$host_name" "$web_port"
    fi
    if [[ -n "$tailscale_host" ]]; then
        printf 'VPN HTTP: http://%s:%s/admin/\n' "$tailscale_host" "$web_port"
    fi
    cat <<EOF
LAN HTTPS: https://$lan_host:$https_port/admin/
Local CA:  $ca_file
MariaDB:  127.0.0.1:$db_port  database=community_mapmaker  user=cmm  password=cmm_local_test
Admin:    localadmin / LocalTestPass!
Editor:   localeditor / LocalTestPass!
Viewer:   localviewer / LocalTestPass!
No access: localunassigned / LocalTestPass!
EOF
}

start_stack() {
    require_tools
    prepare_https
    # Start the database first so an older persisted volume can be migrated
    # before the Web healthcheck queries the current schema.
    compose up --detach --build --wait database
    migrate_stack
    seed_stack
    compose up --detach --build --wait web https-gateway
    wait_for_http
    wait_for_lan_http
    wait_for_tailscale_http
    wait_for_hostname_http
    wait_for_https
    export_ca
    echo 'Local test servers are ready.'
    print_access
}

migrate_stack() {
    require_tools
    local has_activities has_projects migration_parts optional_email_parts
    has_activities=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'activities'")
    if [[ "$has_activities" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/001_activity_core.sql"
        echo 'Applied migration 001_activity_core.sql.'
    else
        echo 'Activity migration is already applied.'
    fi

    has_projects=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'projects'")
    if [[ "$has_projects" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/002_projects_core.sql"
        echo 'Applied migration 002_projects_core.sql.'
    else
        echo 'Project migration is already applied.'
    fi

    migration_parts=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name IN ('role', 'last_login_at')) +
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activities' AND column_name IN ('created_by_user_id', 'updated_by_user_id')) +
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('user_projects', 'admin_audit_logs'))")
    if [[ "$migration_parts" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/003_admin_users.sql"
        echo 'Applied migration 003_admin_users.sql.'
    elif [[ "$migration_parts" == '6' ]]; then
        echo 'Admin user migration is already applied.'
    else
        echo 'Admin user migration is only partially applied; inspect the database before continuing.' >&2
        exit 1
    fi

    optional_email_parts=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users'
            AND column_name IN ('email', 'email_normalized') AND is_nullable = 'YES'")
    if [[ "$optional_email_parts" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/004_optional_user_email.sql"
        echo 'Applied migration 004_optional_user_email.sql.'
    elif [[ "$optional_email_parts" == '2' ]]; then
        echo 'Optional user email migration is already applied.'
    else
        echo 'Optional user email migration is only partially applied; inspect the database before continuing.' >&2
        exit 1
    fi
    local soft_delete_parts
    soft_delete_parts=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'activities'
            AND column_name IN ('is_deleted', 'deleted_at')")
    if [[ "$soft_delete_parts" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/005_activity_soft_delete.sql"
        echo 'Applied migration 005_activity_soft_delete.sql.'
    elif [[ "$soft_delete_parts" == '2' ]]; then
        echo 'Activity soft delete migration is already applied.'
    else
        echo 'Activity soft delete migration is only partially applied; inspect the database before continuing.' >&2
        exit 1
    fi
    local coordinate_parts
    coordinate_parts=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'activities'
            AND column_name IN ('latitude', 'longitude')")
    if [[ "$coordinate_parts" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/006_activity_coordinates.sql"
        echo 'Applied migration 006_activity_coordinates.sql.'
    elif [[ "$coordinate_parts" == '2' ]]; then
        echo 'Activity coordinate migration is already applied.'
    else
        echo 'Activity coordinate migration is only partially applied; inspect the database before continuing.' >&2
        exit 1
    fi
    local bbox_index_count
    bbox_index_count=$(compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker --batch --skip-column-names \
        --execute="SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'activities'
            AND index_name = 'idx_activities_app_active_lon_lat'")
    if [[ "$bbox_index_count" == '0' ]]; then
        compose exec --no-TTY database \
            mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/migrations/007_activity_bbox_index.sql"
        echo 'Applied migration 007_activity_bbox_index.sql.'
    elif [[ "$bbox_index_count" == '4' ]]; then
        echo 'Activity BBOX index migration is already applied.'
    else
        echo 'Activity BBOX index migration is only partially applied; inspect the database before continuing.' >&2
        exit 1
    fi

}

seed_stack() {
    compose exec --no-TTY database \
        mariadb --user=cmm --password=cmm_local_test --database=community_mapmaker < "$project_root/docker/local/seed.sql"
}

test_stack() {
    require_tools
    local base="http://127.0.0.1:$web_port"
    local lan_http_base="http://$lan_host:$web_port"
    local tailscale_http_base=''
    if [[ -n "$tailscale_host" ]]; then
        tailscale_http_base="http://$tailscale_host:$web_port"
    fi
    local https_base="https://$lan_host:$https_port"
    [[ -f "$ca_file" ]] || export_ca
    compose exec --no-TTY --env MARIADB_PWD=cmm_local_test database \
        mariadb-admin ping --user=cmm --silent >/dev/null
    curl --fail --silent --show-error "$base/admin/" >/dev/null
    fetch_schema --fail --silent --show-error "$base/api/activity-schema.php"
    curl --fail --silent --show-error "$base/api/activity-search.php?app=playgrounds&score_min=4" >/dev/null
    if [[ -n "$host_name" && -n "$host_ipv4" && "$host_ipv4" != 127.* ]]; then
        fetch_schema --noproxy '*' --fail --silent --show-error "http://$host_name:$web_port/api/activity-schema.php"
    fi
    curl --fail --silent --show-error --user 'localadmin:LocalTestPass!' "$base/api/projects.php" >/dev/null
    curl --fail --silent --show-error --user 'localadmin:LocalTestPass!' "$base/api/admin-users.php" >/dev/null
    curl --fail --silent --show-error --user 'localeditor:LocalTestPass!' "$base/api/console-session.php" >/dev/null
    fetch_schema --noproxy '*' --fail --silent --show-error \
        --header "Origin: $lan_http_base" "$lan_http_base/api/activity-schema.php"
    if [[ -n "$tailscale_http_base" ]]; then
        fetch_schema --noproxy '*' --fail --silent --show-error \
            --header "Origin: $tailscale_http_base" "$tailscale_http_base/api/activity-schema.php"
    fi
    curl --noproxy '*' --cacert "$ca_file" --fail --silent --show-error "$https_base/admin/" >/dev/null
    fetch_schema --noproxy '*' --cacert "$ca_file" --fail --silent --show-error \
        --header "Origin: $https_base" "$https_base/api/activity-schema.php"
    local editor_status viewer_status unassigned_status
    editor_status=$(curl --silent --output /dev/null --write-out '%{http_code}' --user 'localeditor:LocalTestPass!' \
        --header 'Content-Type: application/json' --data '{"app":"playgrounds","creates":[],"updates":[],"deletes":[]}' "$base/api/activities-batch.php")
    viewer_status=$(curl --silent --output /dev/null --write-out '%{http_code}' --user 'localviewer:LocalTestPass!' \
        --header 'Content-Type: application/json' --data '{"app":"playgrounds","creates":[],"updates":[],"deletes":[]}' "$base/api/activities-batch.php")
    unassigned_status=$(curl --silent --output /dev/null --write-out '%{http_code}' --user 'localunassigned:LocalTestPass!' \
        --header 'Content-Type: application/json' --data '{"app":"playgrounds","creates":[],"updates":[],"deletes":[]}' "$base/api/activities-batch.php")
    [[ "$editor_status" == '200' ]] || { echo "Editor batch access returned HTTP $editor_status" >&2; return 1; }
    [[ "$viewer_status" == '403' ]] || { echo "Viewer batch access returned HTTP $viewer_status" >&2; return 1; }
    [[ "$unassigned_status" == '403' ]] || { echo "Unassigned batch access returned HTTP $unassigned_status" >&2; return 1; }
    echo 'MariaDB, local/LAN/VPN HTTP, LAN HTTPS, CORS, public API, admin APIs, and Project role access: ok'
}

command_name=${1:-help}
case "$command_name" in
    start)
        start_stack
        ;;
    restart)
        require_tools
        compose down --remove-orphans
        start_stack
        ;;
    status)
        require_tools
        compose ps
        if curl --fail --silent --show-error "http://127.0.0.1:$web_port/api/activity-schema.php" >/dev/null 2>&1; then
            echo "HTTP: healthy (http://127.0.0.1:$web_port/)"
        else
            echo 'HTTP: unavailable'
        fi
        if curl --noproxy '*' --fail --silent --show-error "http://$lan_host:$web_port/api/activity-schema.php" >/dev/null 2>&1; then
            echo "LAN HTTP: healthy (http://$lan_host:$web_port/)"
        else
            echo 'LAN HTTP: unavailable'
        fi
        if [[ -n "$tailscale_host" ]]; then
            if curl --noproxy '*' --fail --silent --show-error "http://$tailscale_host:$web_port/api/activity-schema.php" >/dev/null 2>&1; then
                echo "VPN HTTP: healthy (http://$tailscale_host:$web_port/)"
            else
                echo 'VPN HTTP: unavailable'
            fi
        fi
        if [[ -n "$host_name" && -n "$host_ipv4" && "$host_ipv4" != 127.* ]]; then
            if curl --noproxy '*' --fail --silent --show-error "http://$host_name:$web_port/api/activity-schema.php" >/dev/null 2>&1; then
                echo "Host HTTP: healthy (http://$host_name:$web_port/, IPv4 $host_ipv4)"
            else
                echo "Host HTTP: unavailable (http://$host_name:$web_port/, IPv4 $host_ipv4)"
            fi
        fi
        if curl --noproxy '*' --insecure --fail --silent --show-error "https://$lan_host:$https_port/api/activity-schema.php" >/dev/null 2>&1; then
            echo "HTTPS: healthy (https://$lan_host:$https_port/)"
        else
            echo 'HTTPS: unavailable'
        fi
        ;;
    test)
        test_stack
        ;;
    migrate)
        migrate_stack
        ;;
    certificate)
        require_tools
        export_ca
        echo "Local CA certificate: $ca_file"
        ;;
    logs)
        require_tools
        service=${2:-}
        if [[ -n "$service" && "$service" != 'web' && "$service" != 'https-gateway' && "$service" != 'database' ]]; then
            echo 'SERVICE must be web, https-gateway, or database.' >&2
            exit 2
        fi
        if [[ -n "$service" ]]; then
            compose logs --follow --tail=100 "$service"
        else
            compose logs --follow --tail=100
        fi
        ;;
    stop)
        require_tools
        compose down --remove-orphans
        echo 'Local test servers stopped. DB data was preserved.'
        ;;
    reset)
        if [[ ${2:-} != '--yes' ]]; then
            echo 'reset deletes the local test DB. Run: ./scripts/local-test-servers.sh reset --yes' >&2
            exit 2
        fi
        require_tools
        compose down --volumes --remove-orphans
        start_stack
        ;;
    help|-h|--help)
        usage
        ;;
    *)
        echo "Unknown command: $command_name" >&2
        usage >&2
        exit 2
        ;;
esac
