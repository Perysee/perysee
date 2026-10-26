import { spawn, execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { setTimeout as sleep } from 'node:timers/promises';
import { performance } from 'node:perf_hooks';
import { request as httpRequest } from 'node:http';
const selected = process.argv[4]?.split(',');
const send = (mode, payload) => new Promise((resolve, reject) => {
    const req = httpRequest(`http://127.0.0.1:18743/${mode}`, { method: 'POST', headers: { 'Content-Type': 'application/json' } }, response => {
        response.resume();
        response.on('end', () => resolve({ status: response.statusCode, headers: response.headers }));
        response.on('error', reject);
    });
    req.setTimeout(30000, () => req.destroy(Error('Request timed out')));
    req.on('error', reject);
    req.end(JSON.stringify(payload));
});

const seconds = Number(process.argv[2] || 60);
const output = process.argv[3] || '/tmp/perysee-benchmark.json';
if (!Number.isInteger(seconds) || seconds < 1 || seconds > 300) throw Error('Duration must be 1–300 seconds per profile.');
const fixture = JSON.parse(execFileSync('php', ['scripts/benchmarks/fixture.php', 'prepare'], { encoding: 'utf8' }));
const server = spawn('php', ['-S', '127.0.0.1:18743', 'scripts/benchmarks/router.php'], { env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' }, detached: true, stdio: 'ignore' });
const results = { environment: fixture, duration_per_profile_seconds: seconds, recorded_at: new Date().toISOString(), limitations: ['Loopback PHP development server, four workers, not Apache or LiteSpeed', 'Minimal and PDO prototypes deliberately omit production validation, consent, quota, locking and privacy rules', 'Full collector uses process-local array cache to avoid a single simulated IP exhausting its quota; distributed quota performance is excluded', 'P95 excludes HTTP failures; failures and dispatch lag are reported separately', 'Memory is PHP allocated peak per request, not process RSS', 'Storage is a table-wide InnoDB estimate, not physical per-event disk usage'], profiles: [] };
let confirmed = 0;
try {
    await sleep(1500);
    for (const mode of ['full', 'full-no-geo', 'minimal', 'pdo']) {
        for (const rate of [100, 1000, 10000]) {
            if (selected && !selected.includes(`${mode}:${rate}`)) continue;
            const count = Math.max(1, Math.floor(rate * seconds / 60));
            const latencies = [], lags = [], statuses = {}, errors = {};
            let peak = 0;
            const start = performance.now();
            const pending = [];
            for (let i = 0; i < count; i++) {
                const due = start + i * 60000 / rate;
                if (due > performance.now()) await sleep(due - performance.now());
                lags.push(Math.max(0, performance.now() - due));
                pending.push((async () => {
                    const requestStart = performance.now();
                    try {
                        const response = await send(mode, { v: 1, site: fixture.site, type: 'pageview', mode: 'pageviews', host: 'benchmark.invalid', path: '/page-' + (i % 50), consent: true });
                        statuses[response.status] = (statuses[response.status] || 0) + 1;
                        peak = Math.max(peak, Number(response.headers['x-benchmark-peak']) || 0);
                        const error = response.headers['x-benchmark-error'];
                        if (error) errors[error] = (errors[error] || 0) + 1;
                        if (response.status === 204) { latencies.push(performance.now() - requestStart); confirmed++; }
                    } catch (error) { errors[error.name] = (errors[error.name] || 0) + 1; }
                })());
            }
            await Promise.all(pending);
            latencies.sort((a, b) => a - b); lags.sort((a, b) => a - b);
            const percentile = (samples, p) => samples[Math.max(0, Math.ceil(samples.length * p) - 1)] ?? null;
            const result = { mode, events_per_minute: rate, dispatched: count, successful: latencies.length, duration_ms: performance.now() - start, p50_ms: percentile(latencies, .5), p95_ms: percentile(latencies, .95), p99_ms: percentile(latencies, .99), dispatch_lag_p95_ms: percentile(lags, .95), peak_php_bytes: peak, statuses, errors };
            results.profiles.push(result);
            writeFileSync(output, JSON.stringify(results, null, 2) + '\n');
            console.log(JSON.stringify(result));
        }
    }
    results.consolidation = JSON.parse(execFileSync('php', ['scripts/benchmarks/fixture.php', 'cleanup', fixture.site], { encoding: 'utf8', timeout: 330000 }));
    results.expected_confirmed = confirmed;
    results.integrity = results.consolidation.confirmed_events === confirmed && results.consolidation.pageviews === confirmed;
} finally {
    try { process.kill(-server.pid, 'SIGTERM'); } catch {}
    writeFileSync(output, JSON.stringify(results, null, 2) + '\n');
}
if (!results.integrity) throw Error('Confirmed, durable and aggregated counts differ; inspect benchmark output.');
