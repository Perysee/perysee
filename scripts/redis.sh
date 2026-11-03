#!/bin/bash
set -euo pipefail

app_dir="$(cd "$(dirname "$0")/.." && pwd)"
env_file="$app_dir/infra/redis/.env"
name=perysee-redis
image=docker.io/library/redis:8

command -v container >/dev/null || { echo 'Install Apple container: https://github.com/apple/container'; exit 1; }

case "${1:-up}" in
  up)
    if ! container system status >/dev/null 2>&1; then container system start; fi
    if container list --all --quiet | /usr/bin/grep -qx "$name"; then
      if ! container list --quiet | /usr/bin/grep -qx "$name"; then container start "$name"; fi
    else
      if [ ! -f "$env_file" ]; then
        mkdir -p "$(dirname "$env_file")"
        (umask 077; php -r 'echo "REDIS_PASSWORD=".bin2hex(random_bytes(32))."\n";' > "$env_file")
      fi
      # Every key the module writes has an expiry, so evicting only expiring keys is safe. Nothing is persisted.
      container run --detach --name "$name" --label project=perysee \
        --cpus 1 --memory 256M \
        --publish 127.0.0.1:16379:6379 \
        --env-file "$env_file" \
        "$image" sh -c 'exec redis-server --requirepass "$REDIS_PASSWORD" --save "" --appendonly no --maxmemory 128mb --maxmemory-policy volatile-lru'
    fi
    echo 'Redis 8: 127.0.0.1:16379. Check with scripts/redis.sh health.'
    ;;
  stop) container stop "$name" ;;
  health) container exec "$name" sh -c 'redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping' ;;
  logs) container logs "$name" ;;
  status) container list --all ;;
  *) echo 'Usage: scripts/redis.sh [up|stop|health|logs|status]'; exit 1 ;;
esac
