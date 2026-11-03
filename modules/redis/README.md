# Redis

Perysee module `redis`: an optional accelerator that keeps the collection rate limit and the failure counters in Redis.

- `module.json`: identity, version, minimum Core version.
- `src/ModuleServiceProvider.php`: replaces the `App\Support\Coordination` service when `PERYSEE_REDIS_ENABLED=true`, and adds a status card to the instance health page.
- `src/RedisCoordination.php`: the Redis implementation. Keys start with `perysee:` and always expire. When the server cannot be reached, calls are answered by the application cache and the server is left alone for 30 seconds.

Connection settings come from `config/database.php` (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`). The module has no table, route or screen.

## Local server and tests

```sh
bash scripts/redis.sh up
PERYSEE_TEST_REDIS_PORT=16379 PERYSEE_TEST_REDIS_PASSWORD=… php artisan test --compact tests/Feature/RedisModuleTest.php
```

Without `PERYSEE_TEST_REDIS_PORT`, the tests that need a real server are skipped.
