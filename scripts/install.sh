#!/usr/bin/env bash
set -euo pipefail

main() {
    local release_base='https://github.com/mattrbdr/perysee/releases/latest/download'
    local download_base="${PERYSEE_DOWNLOAD_URL:-$release_base}"
    local php_binary="${PERYSEE_PHP:-php}"

    command -v curl >/dev/null || { echo 'Perysee needs curl. Ask your hosting provider to enable it.' >&2; exit 1; }
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

# A function keeps the piped shell source separate from the installer's input.
main "$@"
