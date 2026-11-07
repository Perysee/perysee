#!/usr/bin/env bash
set -euo pipefail

# Global on purpose: the EXIT trap runs after main() has returned, when a local would be gone.
workspace=''

# The PHP CLI to install with. PERYSEE_PHP wins; otherwise the default php when it is recent enough, then the
# newest versioned binary (php8.5...) or cPanel EasyApache one (/opt/cpanel/ea-php85/...) that qualifies.
find_php() {
    if [[ -n "${PERYSEE_PHP:-}" ]]; then
        echo "$PERYSEE_PHP"
        return 0
    fi
    local candidates=(php php8.5 php8.4 php8.3)
    local cpanel=()
    local entry candidate resolved
    shopt -s nullglob
    for entry in "${PERYSEE_CPANEL_ROOT:-/opt/cpanel}"/ea-php*/root/usr/bin/php; do
        cpanel+=("$entry")
    done
    shopt -u nullglob
    # Globs sort ascending: walk the cPanel installations from the newest.
    local index
    for ((index = ${#cpanel[@]} - 1; index >= 0; index--)); do
        candidates+=("${cpanel[$index]}")
    done
    for candidate in "${candidates[@]}"; do
        resolved="$(command -v "$candidate" 2>/dev/null || true)"
        if [[ -n "$resolved" ]] && "$resolved" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1; then
            echo "$resolved"
            return 0
        fi
    done
    echo 'Perysee needs PHP CLI 8.3 or later. Ask your hosting provider to enable it, or set PERYSEE_PHP to its path.' >&2

    return 1
}

main() {
    local release_base='https://github.com/Perysee/perysee/releases/latest/download'
    local download_base="${PERYSEE_DOWNLOAD_URL:-$release_base}"
    local php_binary

    command -v curl >/dev/null || { echo 'Perysee needs curl. Ask your hosting provider to enable it.' >&2; exit 1; }
    php_binary="$(find_php)"
    command -v "$php_binary" >/dev/null || { echo 'Perysee needs PHP CLI 8.3 or later.' >&2; exit 1; }
    "$php_binary" -r 'if (PHP_VERSION_ID < 80300) {fwrite(STDERR, "Perysee needs PHP CLI 8.3 or later.\n"); exit(1);}'
    case "$download_base" in
        https://*) ;;
        *) echo 'The Perysee download address must use HTTPS.' >&2; exit 1 ;;
    esac
    download_base="${download_base%/}"
    workspace="$(mktemp -d "${TMPDIR:-/tmp}/perysee-bootstrap.XXXXXX")"
    chmod 700 "$workspace"
    trap 'rm -rf -- "$workspace"' EXIT

    echo 'Downloading the Perysee installer…'
    curl -fsSL --proto '=https' --proto-redir '=https' "$download_base/SHA256SUMS" --output "$workspace/SHA256SUMS"
    curl -fsSL --proto '=https' --proto-redir '=https' "$download_base/install.php" --output "$workspace/install.php"
    "$php_binary" -r '
        $manifest = file_get_contents($argv[1]);
        if (!preg_match("/^([a-f0-9]{64})  install\\.php\\r?$/m", $manifest, $match)
            || !hash_equals($match[1], hash_file("sha256", $argv[2]))) {
            fwrite(STDERR, "Installer checksum verification failed. Nothing was installed.\n");
            exit(1);
        }
    ' "$workspace/SHA256SUMS" "$workspace/install.php"
    curl -fsSL --proto '=https' --proto-redir '=https' "$download_base/perysee.zip" --output "$workspace/perysee.zip"

    # When invoked through curl | bash, keep PHP's prompts on the terminal.
    if [[ -t 0 ]]; then
        "$php_binary" "$workspace/install.php" --archive "$workspace/perysee.zip" --checksums "$workspace/SHA256SUMS" "$@"
    elif ( : < /dev/tty ) 2>/dev/null; then
        "$php_binary" "$workspace/install.php" --archive "$workspace/perysee.zip" --checksums "$workspace/SHA256SUMS" "$@" < /dev/tty
    else
        "$php_binary" "$workspace/install.php" --archive "$workspace/perysee.zip" --checksums "$workspace/SHA256SUMS" "$@" < /dev/null
    fi
}

# A function keeps the piped shell source separate from the installer's input. Tests source this file to reach
# find_php without installing anything.
if [[ -z "${PERYSEE_INSTALL_SOURCED:-}" ]]; then
    main "$@"
fi
