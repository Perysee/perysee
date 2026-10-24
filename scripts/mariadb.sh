#!/bin/bash
set -euo pipefail

app_dir="$(cd "$(dirname "$0")/.." && pwd)"
env_file="$app_dir/infra/mariadb/.env"
name=perysee-mariadb
volume=perysee-mariadb-data
image=docker.io/library/mariadb:11.4.8

command -v container >/dev/null || { echo 'Install Apple container: https://github.com/apple/container'; exit 1; }

case "${1:-up}" in
  up)
    if ! container system status >/dev/null 2>&1; then container system start; fi
    if container list --all --quiet | /usr/bin/grep -qx "$name"; then
      if ! container list --quiet | /usr/bin/grep -qx "$name"; then container start "$name"; fi
    else
      if [ ! -f "$env_file" ]; then
        (umask 077; php -r '
          $password = bin2hex(random_bytes(24));
          $rootPassword = bin2hex(random_bytes(32));
          echo "MARIADB_DATABASE=perysee\nMARIADB_USER=perysee\nMARIADB_PASSWORD=$password\nMARIADB_ROOT_PASSWORD=$rootPassword\n";
        ' > "$env_file")
      fi
      container volume inspect "$volume" >/dev/null 2>&1 || container volume create --label project=perysee "$volume"
      container run --detach --name "$name" --label project=perysee \
        --cpus 2 --memory 1G \
        --publish 127.0.0.1:13306:3306 \
        --env-file "$env_file" \
        --volume "$volume:/var/lib/mysql" \
        "$image" --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --default-time-zone=+00:00
    fi
    echo 'MariaDB 11.4.8: 127.0.0.1:13306. Check with scripts/mariadb.sh health.'
    ;;
  test-db)
    test_env="$app_dir/infra/mariadb/.env.testing"
    if [ ! -f "$test_env" ]; then
      (umask 077; php -r 'echo "MARIADB_TEST_PASSWORD=".bin2hex(random_bytes(32))."\n";' > "$test_env")
    fi
    test_password="$(sed -n 's/^MARIADB_TEST_PASSWORD=//p' "$test_env")"
    [[ "$test_password" =~ ^[a-f0-9]{64}$ ]] || { echo 'Invalid test credential file.'; exit 1; }
    container exec --interactive "$name" sh -c 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb -uroot' <<SQL
CREATE DATABASE IF NOT EXISTS perysee_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'perysee_test'@'%' IDENTIFIED BY '$test_password';
ALTER USER 'perysee_test'@'%' IDENTIFIED BY '$test_password';
GRANT ALL PRIVILEGES ON perysee_test.* TO 'perysee_test'@'%';
SQL
    echo 'Dedicated perysee_test database and restricted user ready.'
    ;;
  stop) container stop "$name" ;;
  health) container exec "$name" healthcheck.sh --connect --innodb_initialized ;;
  logs) container logs "$name" ;;
  status) container list --all ;;
  *) echo 'Usage: scripts/mariadb.sh [up|stop|health|logs|status|test-db]'; exit 1 ;;
esac
