// Pure helpers of the "Verify the installation" page: they classify what the browser saw, so the page only
// has to display the verdict.

/** The request the "Test now" button sends to the real collector. */
export function probeRequest(issued, root) {
    return {
        url: String(root).replace(/\/+$/, "") + "/api/collect",
        init: {
            method: "POST",
            mode: "cors",
            credentials: "omit",
            cache: "no-store",
            referrerPolicy: "no-referrer",
            headers: { "Content-Type": "application/json", "X-Perysee-Probe": issued.token },
            body: JSON.stringify({ v: 1, site: issued.site, type: "pageview", mode: issued.mode, host: issued.host, path: "/", consent: true }),
        },
    };
}

/**
 * What the collector's answer to a test means. `result` is { error } when the request never completed,
 * or { status, echoed, contentType } with `echoed` telling whether the signed header came back.
 */
export function classifyProbe(result) {
    if (!result || result.error)
        return { outcome: "unreachable", level: "error", title: "The collector did not answer", message: "The request never completed: the address is wrong, the connection was cut, or a firewall or the browser blocked it.", action: "Check APP_URL and http or https, the .htaccess or firewall rules on /api/collect, and that the public folder is the one served." };
    const { status, echoed, contentType = "" } = result;
    if (status === 204 && echoed)
        return { outcome: "ok", level: "success", title: "The collector answered", message: "The collector, the site key and the database all work. This test is sent from the administration, so it does not prove the allowed domains or the consent settings of your site.", action: "Browse your real site, then reload this page: only a real event proves the whole chain." };
    if (status === 204)
        return { outcome: "foreign", level: "error", title: "Something else answered", message: "A reply came back, but not from the Perysee collector: the signed header was not returned.", action: "A proxy, cache or firewall sits in front of /api/collect. Let POST requests through to Perysee untouched." };
    if (status === 429)
        return { outcome: "throttled", level: "warning", title: "Too many tests", message: "The test limit was reached for this site.", action: "Wait a minute and try again." };
    if (status === 503)
        return { outcome: "database", level: "error", title: "The collector could not use its database", message: "The collector answered but could not read or write the database, or the installation is unfinished.", action: "Open Instance health and check the database connection and the failure counter." };
    if (status === 404)
        return { outcome: "missing", level: "error", title: "The collector address was not found", message: "The server has no /api/collect at this address, or the site is being deleted.", action: "Check that the web server sends every request to public/index.php and that the folder is the right one." };
    if (status === 403 || /html/i.test(contentType))
        return { outcome: "blocked", level: "error", title: "The request was refused before reaching Perysee", message: "The reply is a refusal or a web page, not the collector's: a firewall or a rule of the server answered.", action: "Allow POST requests to /api/collect in the WAF, .htaccess or hosting firewall." };
    return { outcome: "unexpected", level: "error", title: "Unexpected answer (" + status + ")", message: "The collector replied with a status it should not use for a valid test.", action: "Reload the page and try again; if it persists, check the instance logs." };
}

/** Whether the tracking script can be loaded by a page of another origin, and is really the script. */
export function classifyScript(result) {
    if (!result || result.error)
        return { outcome: "unreachable", level: "error", title: "The script cannot be loaded", message: "The browser could not fetch the script, or the server does not allow other sites to load it.", action: "Check the script address in the snippet and that the server returns the script with a permissive CORS header." };
    if (result.status === 503)
        return { outcome: "unbuilt", level: "error", title: "The script is not built", message: "The server answers but the tracking script file is missing.", action: "Rebuild or reinstall the release so that public/tracker/lueur.v1.js exists." };
    if (result.status !== 200 || /html/i.test(result.contentType ?? "") || !/lueur/i.test(result.body ?? ""))
        return { outcome: "wrong", level: "error", title: "The script address does not serve the script", message: "The address answers with something that is not the Lueur script.", action: "Check the web server rules for /lueur.js and that the public folder is the one served." };
    return { outcome: "ok", level: "success", title: "The script is served", message: "The script loads from this address.", action: null };
}

/**
 * The snippet loads the script from the address the administration was opened with. When the administration
 * is opened from another address, the test and the snippet may not describe what visitors will see.
 */
export function compareOrigins(adminOrigin, collectorRoot) {
    let admin, collector;
    try {
        admin = new URL(adminOrigin);
        collector = new URL(collectorRoot);
    } catch {
        return null;
    }
    if (admin.origin === collector.origin) return null;
    const bare = (host) => host.replace(/^www\./, "");
    if (admin.protocol !== collector.protocol)
        return { code: "scheme", message: "The snippet loads the script over " + collector.protocol.slice(0, -1) + ", but this page is open over " + admin.protocol.slice(0, -1) + ". Visitors on an https page cannot load an http script.", action: "Set APP_URL to the address Perysee is really served from, with the right protocol." };
    if (admin.hostname === collector.hostname)
        return { code: "port", message: "The snippet points to " + collector.origin + " while this page is open at " + admin.origin + ".", action: "Set APP_URL to the address Perysee is really served from." };
    if (bare(admin.hostname) === bare(collector.hostname))
        return { code: "www", message: "The snippet points to " + collector.host + " while this page is open at " + admin.host + ": www and the bare domain are different addresses.", action: "Set APP_URL to the address you want visitors to load the script from, and reinstall the snippet." };
    return { code: "host", message: "The snippet loads the script from " + collector.host + ", not from " + admin.host + " where you are signed in.", action: "If that is not intended, set APP_URL to the address Perysee is really served from." };
}

/** "10 000+" for a capped count, plain digits otherwise. */
export function formatVolume(volume, locale = "en") {
    const digits = new Intl.NumberFormat(locale).format(volume.count);
    return volume.capped ? digits + "+" : digits;
}
