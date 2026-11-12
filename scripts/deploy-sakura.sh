#!/bin/sh
set -eu

usage() {
    cat <<'USAGE'
Usage: sh scripts/deploy-sakura.sh [PUBLIC_DIR]

Copy www/ to Sakura's public directory; keep this repository outside $HOME/www.
Default PUBLIC_DIR: $HOME/www/community-mapmaker-backend
CMM_SAKURA_CONFIG_FILE: private config path (default: <repository>/config/config.php)
USAGE
}
case "${1:-}" in -h|--help) usage; exit 0 ;; esac
[ "$#" -le 1 ] || { usage >&2; exit 1; }
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
PUBLIC_DIR=${1:-"${HOME:?HOME is not set}/www/community-mapmaker-backend"}
CONFIG_FILE=${CMM_SAKURA_CONFIG_FILE:-"$REPO_ROOT/config/config.php"}
case "$CONFIG_FILE" in /*) ;; *) CONFIG_FILE="$REPO_ROOT/$CONFIG_FILE" ;; esac
[ -f "$CONFIG_FILE" ] || { echo "ERROR: private config file not found: $CONFIG_FILE" >&2; exit 1; }
# Resolve existing symlinks and .. before checking boundaries or replacing files.
PUBLIC_DIR=$(php -r '
$p = $argv[1];
if ($p[0] !== "/") $p = getcwd() . "/" . $p;
$tail = [];
while (!file_exists($p)) { array_unshift($tail, basename($p)); $p = dirname($p); }
$p = realpath($p);
foreach ($tail as $part) { if ($part === "..") $p = dirname($p); elseif ($part !== ".") $p .= "/" . $part; }
echo rtrim($p, "/") ?: "/";
' "$PUBLIC_DIR")
[ "$PUBLIC_DIR" != / ] || { echo "ERROR: PUBLIC_DIR must not be filesystem root." >&2; exit 1; }
CONFIG_FILE=$(php -r 'echo realpath($argv[1]);' "$CONFIG_FILE")
case "$PUBLIC_DIR/" in
    "$REPO_ROOT/"*) echo 'ERROR: PUBLIC_DIR must be outside the repository.' >&2; exit 1 ;;
esac
case "$REPO_ROOT/" in
    "$PUBLIC_DIR/"*) echo 'ERROR: PUBLIC_DIR must not contain the private repository.' >&2; exit 1 ;;
esac
case "$CONFIG_FILE" in
    "$PUBLIC_DIR/"*) echo 'ERROR: configuration must be outside PUBLIC_DIR.' >&2; exit 1 ;;
esac
case "$REPO_ROOT/" in
    "${HOME}/www/"*) echo 'ERROR: keep the private repository outside $HOME/www.' >&2; exit 1 ;;
esac

# Generate the only installation-specific file before touching the destination.
RUNTIME_FILE=$(mktemp)
trap 'rm -f "$RUNTIME_FILE"' EXIT HUP INT TERM
php -r '
echo "<?php\ndeclare(strict_types=1);\n";
echo "define(" . var_export("CMM_PRIVATE_ROOT", true) . ", " . var_export($argv[1], true) . ");\n";
echo "define(" . var_export("CMM_PUBLIC_ROOT", true) . ", __DIR__);\n";
echo "putenv(" . var_export("CMM_AUTH_CONFIG=" . $argv[2], true) . ");\n";
' "$REPO_ROOT" "$CONFIG_FILE" > "$RUNTIME_FILE"

mkdir -p "$PUBLIC_DIR"
# Replace only application-owned directories. Other files in the destination stay intact.
for dir in api auth admin en ja; do
    rm -rf "$PUBLIC_DIR/$dir"
done
cp -R "$REPO_ROOT/www/." "$PUBLIC_DIR/"
cp "$RUNTIME_FILE" "$PUBLIC_DIR/_runtime.php"
# Remove private runtime copies left by the previous deployment layout.
rm -rf "$PUBLIC_DIR/lib" "$PUBLIC_DIR/config"
rm -f "$PUBLIC_DIR/bootstrap.php"

find "$PUBLIC_DIR" -type d -exec chmod 755 {} \;
find "$PUBLIC_DIR" -type f -exec chmod 644 {} \;
find "$PUBLIC_DIR" -type f -name '*.php' -exec chmod 755 {} \;
echo "Public files: $PUBLIC_DIR"
echo "Private code: $REPO_ROOT (bootstrap.php, lib/, config/, scripts/, migrations/)"
echo "Private config: $CONFIG_FILE"
