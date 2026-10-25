const assets = new Map([
    ['install.sh', 'text/x-shellscript; charset=utf-8'],
    ['install.php', 'application/octet-stream'],
    ['perysee.zip', 'application/zip'],
    ['SHA256SUMS', 'text/plain; charset=utf-8']
]);
const versionPattern = /^v\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/;

function publicResponse(body, status, headers = {}) {
    return new Response(body, { status, headers: {
        'X-Content-Type-Options': 'nosniff',
        'Cache-Control': 'no-store',
        ...headers
    } });
}

/** Expose release assets only. GitHub credentials never reach a redirect host or client. */
export async function handleDownload(request, env, context, { fetch: upstream = fetch, cache = globalThis.caches?.default } = {}) {
    if (!['GET', 'HEAD'].includes(request.method)) {
        return publicResponse('Method not allowed.\n', 405, { Allow: 'GET, HEAD' });
    }
    const url = new URL(request.url);
    const pinned = url.pathname.match(/^\/releases\/(v[^/]+)\/([^/]+)$/);
    const bootstrap = url.pathname === '/';
    const alias = assets.has(url.pathname.slice(1)) ? url.pathname.slice(1) : null;
    if (url.search || (!bootstrap && !alias && (!pinned || !versionPattern.test(pinned[1]) || !assets.has(pinned[2])))) {
        return publicResponse('Not found.\n', 404);
    }
    const cacheKey = new Request(url, { method: 'GET' });
    const cached = await cache?.match(cacheKey);
    if (cached) {
        return request.method === 'HEAD' ? new Response(null, cached) : cached;
    }
    try {
        const repository = env.GITHUB_REPOSITORY || 'mattrbdr/perysee';
        if (!/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/.test(repository) || !env.GITHUB_TOKEN) {
            throw new Error('Download gateway is not configured.');
        }
        const api = `https://api.github.com/repos/${repository}/releases/`;
        const headers = {
            Authorization: `Bearer ${env.GITHUB_TOKEN}`,
            Accept: 'application/vnd.github+json',
            'User-Agent': 'Perysee-Downloads',
            'X-GitHub-Api-Version': '2022-11-28'
        };
        const metadata = await upstream(`${api}${pinned ? `tags/${encodeURIComponent(pinned[1])}` : 'latest'}`, { headers, redirect: 'error' });
        if (!metadata.ok) throw new Error('Release unavailable.');
        const release = await metadata.json();
        if (release.draft || !versionPattern.test(release.tag_name) || (!pinned && release.prerelease) || (pinned && release.tag_name !== pinned[1])) {
            throw new Error('Release unavailable.');
        }
        const releaseBase = `${url.origin}/releases/${release.tag_name}`;
        let response;
        if (alias) {
            response = publicResponse(null, 302, { Location: `${releaseBase}/${alias}`, 'Cache-Control': 'public, max-age=60' });
        } else {
            const name = bootstrap ? 'install.sh' : pinned[2];
            const matching = (release.assets || []).filter(asset => asset.name === name);
            if (matching.length !== 1 || !Number.isSafeInteger(matching[0].id)) throw new Error('Release asset unavailable.');
            let download = await upstream(`${api}assets/${matching[0].id}`, {
                headers: { ...headers, Accept: 'application/octet-stream' }, redirect: 'manual'
            });
            if ([301, 302, 303, 307, 308].includes(download.status)) {
                const destination = new URL(download.headers.get('location'));
                if (destination.protocol !== 'https:' || !destination.hostname.endsWith('.githubusercontent.com') || destination.username || destination.password) {
                    throw new Error('Unexpected asset destination.');
                }
                download = await upstream(destination.href, { redirect: 'error' });
            }
            if (!download.ok) throw new Error('Release asset unavailable.');
            const body = bootstrap
                ? `export PERYSEE_DOWNLOAD_URL='${releaseBase}'\n${await download.text()}`
                : download.body;
            response = publicResponse(body, 200, {
                'Content-Type': bootstrap ? assets.get('install.sh') : assets.get(name),
                'Cache-Control': bootstrap ? 'public, max-age=60' : 'public, max-age=31536000, immutable',
                ...(bootstrap ? {} : { 'Content-Disposition': `attachment; filename="${name}"` })
            });
        }
        if (cache && context.waitUntil) context.waitUntil(cache.put(cacheKey, response.clone()));
        return request.method === 'HEAD' ? new Response(null, response) : response;
    } catch {
        return publicResponse('Perysee downloads are temporarily unavailable. Please try again later.\n', 503, { 'Retry-After': '60' });
    }
}

export default { fetch: handleDownload };
