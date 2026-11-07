import { test } from "node:test";
import assert from "node:assert/strict";
import { classifyProbe, classifyScript, compareOrigins, formatVolume, probeRequest } from "../../resources/js/tracker-check.mjs";

test("the probe goes to the real collector with the signed header and no credentials", () => {
    const { url, init } = probeRequest({ token: "123.abc", site: "uuid", mode: "sessions", host: "example.org" }, "https://stats.example.org/");
    assert.equal(url, "https://stats.example.org/api/collect");
    assert.equal(init.headers["X-Perysee-Probe"], "123.abc");
    assert.equal(init.credentials, "omit");
    assert.equal(init.method, "POST");
    assert.deepEqual(JSON.parse(init.body), { v: 1, site: "uuid", type: "pageview", mode: "sessions", host: "example.org", path: "/", consent: true });
});

test("only an acknowledged, echoed probe is a success", () => {
    assert.equal(classifyProbe({ status: 204, echoed: true }).outcome, "ok");
    assert.equal(classifyProbe({ status: 204, echoed: true }).level, "success");
    assert.equal(classifyProbe({ status: 204, echoed: false }).outcome, "foreign");
});

test("each failure gets its own verdict and an action", () => {
    const cases = [
        [{ error: true }, "unreachable"], [undefined, "unreachable"], [{ status: 429 }, "throttled"], [{ status: 503 }, "database"],
        [{ status: 404 }, "missing"], [{ status: 403 }, "blocked"], [{ status: 200, contentType: "text/html; charset=UTF-8" }, "blocked"], [{ status: 500 }, "unexpected"],
    ];
    for (const [result, outcome] of cases) {
        const verdict = classifyProbe(result);
        assert.equal(verdict.outcome, outcome);
        assert.ok(verdict.action.length > 10);
        assert.notEqual(verdict.level, "success");
    }
});

test("the success message states what the test cannot prove", () => {
    assert.match(classifyProbe({ status: 204, echoed: true }).message, /allowed domains/);
});

test("the script check tells served, unbuilt, wrong and unreachable apart", () => {
    assert.equal(classifyScript({ status: 200, contentType: "application/javascript", body: "/* Lueur · Perysee */" }).outcome, "ok");
    assert.equal(classifyScript({ status: 503, body: "" }).outcome, "unbuilt");
    assert.equal(classifyScript({ status: 200, contentType: "text/html", body: "<html>Lueur</html>" }).outcome, "wrong");
    assert.equal(classifyScript({ status: 200, contentType: "application/javascript", body: "nothing" }).outcome, "wrong");
    assert.equal(classifyScript({ error: true }).outcome, "unreachable");
});

test("origin differences are named", () => {
    assert.equal(compareOrigins("https://a.example", "https://a.example"), null);
    assert.equal(compareOrigins("https://a.example", "http://a.example").code, "scheme");
    assert.equal(compareOrigins("https://a.example:8443", "https://a.example").code, "port");
    assert.equal(compareOrigins("https://www.a.example", "https://a.example").code, "www");
    assert.equal(compareOrigins("https://a.example", "https://b.example").code, "host");
    assert.equal(compareOrigins("not a url", "https://b.example"), null);
});

test("a capped volume says so", () => {
    assert.equal(formatVolume({ count: 10000, capped: true }), "10,000+");
    assert.equal(formatVolume({ count: 42, capped: false }), "42");
});
