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

fail() {
    echo "$1" >&2
    exit 1
}

# prompt <question> <variable>: reads the answer from file descriptor 3, opened on the terminal by the caller.
prompt() {
    # Not named "answer" or similar: a local with the caller's variable name would shadow it.
    local prompt_reply
    read -r -p "$1" prompt_reply <&3
    printf -v "$2" '%s' "$prompt_reply"
}

fetch() {
    curl -fsSL --proto '=https' --proto-redir '=https' "$1" --output "$2"
}

# SHA-256 of a file, with whichever tool the system has.
file_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

# Fails unless $1 in the workspace is the file that SHA256SUMS lists under that name.
verify_download() {
    local expected
    expected="$(awk -v name="$1" '$2 == name { print $1 }' "$workspace/SHA256SUMS")"
    if [[ ! "$expected" =~ ^[a-f0-9]{64}$ ]] || [[ "$(file_sha256 "$workspace/$1")" != "$expected" ]]; then
        fail "Release checksum verification failed for $1. Nothing was installed."
    fi
}

# The mode that fits this machine, suggested as the menu's default: a cPanel account has the uapi command, a working
# "docker compose" means Docker, and anything else is a manual installation.
detect_mode() {
    if command -v uapi >/dev/null 2>&1 || [[ -x "${PERYSEE_UAPI_PATH:-/usr/local/cpanel/bin/uapi}" ]]; then
        echo cpanel
    elif command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        echo docker
    else
        echo manual
    fi
}

# Opens the terminal on file descriptor 3 (once), for questions that must work under curl | bash. PERYSEE_TTY lets
# tests supply the answers. Fails quietly when there is no terminal.
open_terminal() {
    { : <&3; } 2>/dev/null && return 0
    local terminal="${PERYSEE_TTY:-/dev/tty}"
    if ( : < "$terminal" ) 2>/dev/null; then
        exec 3< "$terminal"
        return 0
    fi

    return 1
}

# select_mode <variable>: asks how to install and stores cpanel, docker or manual in the variable.
select_mode() {
    local suggestion choice
    suggestion="$(detect_mode)"
    echo 'How do you want to install Perysee?'
    echo '  1) cPanel  - creates the subdomain, database, PHP version and cron through UAPI (needs SSH on the account)'
    echo '  2) Docker  - builds and runs Perysee, its scheduler and MariaDB with Docker Compose'
    echo '  3) Manual  - extracts the files; you prepare the web server and the database yourself'
    prompt "Choice [$suggestion]: " choice
    case "${choice:-$suggestion}" in
        1 | cpanel) printf -v "$1" '%s' cpanel ;;
        2 | docker) printf -v "$1" '%s' docker ;;
        3 | manual) printf -v "$1" '%s' manual ;;
        *) fail "Unknown choice: ${choice}. Answer 1, 2 or 3 (or pass --mode cpanel, docker or manual)." ;;
    esac
}

# Docker needs no PHP on the host: the image is built from the verified release, here, and nothing is pulled from
# a Perysee registry. Writes <target>/.env with generated secrets (never an administrator password).
docker_install() {
    local download_base="$1"
    shift
    local target="${HOME:-.}/perysee-docker" url='' port='8080' php_version='8.4' mariadb_version='11.4'
    local admin_name='Administrator' admin_email='' yes=0 upgrade=0
    while (($#)); do
        case "$1" in
            --*=*) set -- "${1%%=*}" "${1#*=}" "${@:2}" ;;
        esac
        case "$1" in
            --mode) shift 2 ;;
            --target) target="${2:?--target needs a folder}"; shift 2 ;;
            --url) url="${2:?--url needs an address}"; shift 2 ;;
            --port) port="${2:?--port needs a number}"; shift 2 ;;
            --php) php_version="${2:?--php needs a version}"; shift 2 ;;
            --mariadb) mariadb_version="${2:?--mariadb needs a version}"; shift 2 ;;
            --admin-name) admin_name="${2:?--admin-name needs a name}"; shift 2 ;;
            --admin-email) admin_email="${2:?--admin-email needs an address}"; shift 2 ;;
            --yes) yes=1; shift ;;
            --upgrade) upgrade=1; shift ;;
            *) fail "Unknown option for the Docker mode: $1" ;;
        esac
    done
    if ((yes == 0)); then
        open_terminal || fail 'No terminal to ask questions. Use --yes with --url and --admin-email.'
    fi
    command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1 \
        || fail 'Perysee needs Docker with the compose plugin (the "docker compose" command). Install Docker Engine or Docker Desktop first.'
    command -v openssl >/dev/null 2>&1 || fail 'Perysee needs openssl to generate the secrets.'
    [[ "$port" =~ ^[0-9]+$ ]] && ((port >= 1 && port <= 65535)) || fail 'The port must be a number between 1 and 65535.'
    [[ "$php_version" =~ ^8\.(3|4|5)$ ]] || fail 'PHP must be 8.3, 8.4 or 8.5.'
    [[ "$mariadb_version" =~ ^(10\.11|11\.4|11\.8)$ ]] || fail 'MariaDB must be 10.11, 11.4 or 11.8.'
    if ((upgrade)); then
        [[ -f "$target/compose.yaml" && -f "$target/.env" ]] || fail "No Docker installation found in $target. Use --target with the folder of an existing installation."
    else
        if [[ -z "$url" && "$yes" -eq 0 ]]; then
            prompt 'Public HTTPS address (for example https://stats.example.com): ' url
        fi
        [[ "$url" =~ ^https://[^/?#@[:space:]]+$ ]] || fail 'Provide the public address as an HTTPS origin such as https://stats.example.com, with no path. Put a reverse proxy with HTTPS in front of the container.'
        if [[ -z "$admin_email" ]]; then
            ((yes == 0)) || fail 'With --yes, provide the administrator with --admin-email.'
            prompt 'Administrator email: ' admin_email
        fi
        if [[ -e "$target" ]] && [[ -n "$(ls -A "$target" 2>/dev/null)" ]]; then
            fail "The destination is not empty: $target. Existing installations are never overwritten; use --upgrade to update one."
        fi
    fi
    if ((yes == 0)); then
        local answer
        prompt "Build and start Perysee in $target? Type yes [no]: " answer
        [[ "$answer" == 'yes' ]] || { echo 'Installation cancelled.'; return 0; }
    fi

    workspace="$(mktemp -d "${TMPDIR:-/tmp}/perysee-bootstrap.XXXXXX")"
    chmod 700 "$workspace"
    trap 'rm -rf -- "$workspace"' EXIT
    echo 'Downloading the Perysee release…'
    fetch "$download_base/SHA256SUMS" "$workspace/SHA256SUMS"
    local name
    for name in perysee.zip Dockerfile compose.yaml docker-entrypoint.sh; do
        fetch "$download_base/$name" "$workspace/$name"
        verify_download "$name"
    done

    mkdir -p "$target"
    for name in perysee.zip Dockerfile compose.yaml docker-entrypoint.sh; do
        cp "$workspace/$name" "$target/$name"
    done
    if ((upgrade)); then
        docker compose --project-directory "$target" build --pull
        docker compose --project-directory "$target" up -d --wait
        echo "Perysee was updated in $target. Pending migrations ran when the application started."
        return 0
    fi
    (
        umask 077
        {
            echo "APP_URL=$url"
            echo "APP_KEY=base64:$(openssl rand -base64 32)"
            echo 'DB_DATABASE=perysee'
            echo 'DB_USERNAME=perysee'
            echo "DB_PASSWORD=$(openssl rand -hex 24)"
            echo "MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)"
            echo "MARIADB_VERSION=$mariadb_version"
            echo "PHP_VERSION=$php_version"
            echo "PERYSEE_PORT=$port"
            echo 'PERYSEE_BIND=127.0.0.1'
            echo 'PERYSEE_TRUSTED_PROXIES='
        } > "$target/.env"
    )
    docker compose --project-directory "$target" up -d --build --wait
    local exec_flags=() install_flags=()
    if ((yes)); then
        exec_flags=(-T)
        install_flags=(--no-interaction)
    fi
    # An empty array must not be expanded bare: bash 3.2 (macOS) treats it as unset under set -u.
    # The command reads its hidden password prompt from the terminal, or nothing at all when unattended.
    if ((yes)); then
        exec 0< /dev/null
    else
        exec 0<&3
    fi
    docker compose --project-directory "$target" exec ${exec_flags[@]+"${exec_flags[@]}"} --user www-data app php artisan perysee:install \
        "--url=$url" --db-host=mariadb --db-name=perysee --db-user=perysee "--admin-name=$admin_name" "--admin-email=$admin_email" ${install_flags[@]+"${install_flags[@]}"}
    echo
    echo "Perysee runs in $target and listens on 127.0.0.1:$port."
    echo "Put a reverse proxy with HTTPS for $url in front of it, and list the proxy's address in PERYSEE_TRUSTED_PROXIES (in $target/.env)."
    echo "To update later: install.sh --mode docker --upgrade --target $target"
}

main() {
    local release_base='https://github.com/Perysee/perysee/releases/latest/download'
    local download_base="${PERYSEE_DOWNLOAD_URL:-$release_base}"
    local php_binary mode='' previous='' argument

    command -v curl >/dev/null || { echo 'Perysee needs curl. Ask your hosting provider to enable it.' >&2; exit 1; }
    case "$download_base" in
        https://*) ;;
        *) echo 'The Perysee download address must use HTTPS.' >&2; exit 1 ;;
    esac
    download_base="${download_base%/}"
    for argument in "$@"; do
        if [[ "$previous" == '--mode' ]]; then
            mode="$argument"
        elif [[ "$argument" == --mode=* ]]; then
            mode="${argument#--mode=}"
        fi
        previous="$argument"
    done
    # Without --mode, a person at a terminal chooses; unattended runs (--yes) keep the manual installation.
    if [[ -z "$mode" ]] && [[ " $* " != *' --yes '* ]] && open_terminal; then
        select_mode mode
        set -- "$@" --mode "$mode"
    fi
    if [[ "$mode" == 'docker' ]]; then
        docker_install "$download_base" "$@"
        return 0
    fi

    php_binary="$(find_php)"
    command -v "$php_binary" >/dev/null || { echo 'Perysee needs PHP CLI 8.3 or later.' >&2; exit 1; }
    "$php_binary" -r 'if (PHP_VERSION_ID < 80300) {fwrite(STDERR, "Perysee needs PHP CLI 8.3 or later.\n"); exit(1);}'
    workspace="$(mktemp -d "${TMPDIR:-/tmp}/perysee-bootstrap.XXXXXX")"
    chmod 700 "$workspace"
    trap 'rm -rf -- "$workspace"' EXIT

    echo 'Downloading the Perysee installer…'
    fetch "$download_base/SHA256SUMS" "$workspace/SHA256SUMS"
    fetch "$download_base/install.php" "$workspace/install.php"
    "$php_binary" -r '
        $manifest = file_get_contents($argv[1]);
        if (!preg_match("/^([a-f0-9]{64})  install\\.php\\r?$/m", $manifest, $match)
            || !hash_equals($match[1], hash_file("sha256", $argv[2]))) {
            fwrite(STDERR, "Installer checksum verification failed. Nothing was installed.\n");
            exit(1);
        }
    ' "$workspace/SHA256SUMS" "$workspace/install.php"
    fetch "$download_base/perysee.zip" "$workspace/perysee.zip"

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
