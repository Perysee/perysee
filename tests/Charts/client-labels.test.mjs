import { test } from "node:test";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import {
    clientLabels,
    describeClient,
} from "../../resources/js/client-labels.mjs";

test("browsers and device brands expose a color logo", () => {
    assert.equal(describeClient("browser", "firefox").logo, "firefox");
    assert.equal(describeClient("browser", "ie").logo, "ie");
    assert.equal(describeClient("device_brand", "apple").logo, "apple");
    assert.equal(describeClient("device_brand", "xiaomi").logo, "xiaomi");
});

test("values without an official logo fall back to the icon", () => {
    for (const [type, value] of [
        ["browser", "chromium"],
        ["browser", "samsung"],
        ["browser", "unknown"],
        ["device_brand", "unknown"],
        ["device", "desktop"],
        ["browser", "never-seen"],
    ]) {
        const client = describeClient(type, value);
        assert.equal(client.logo, null, `${type}:${value}`);
        assert.match(client.icon, /^i-tabler-/);
    }
});

test("every declared logo ships in public/brands/color", () => {
    for (const type of ["browser", "device_brand"]) {
        for (const value of Object.keys(clientLabels[type])) {
            const { logo } = describeClient(type, value);
            if (logo)
                assert.ok(
                    existsSync(
                        new URL(
                            `../../public/brands/color/${logo}.svg`,
                            import.meta.url,
                        ),
                    ),
                    `${type}:${value} → ${logo}.svg`,
                );
        }
    }
});

test("filter values resolve to a logo by dimension", async () => {
    const { filterLogo } = await import(
        "../../resources/js/client-labels.mjs"
    );
    assert.equal(filterLogo("provider", "claude"), "claude");
    assert.equal(filterLogo("source", "claude.ai"), "claude");
    assert.equal(filterLogo("source", "chatgpt.com"), "chatgpt");
    assert.equal(filterLogo("browser", "firefox"), "firefox");
    assert.equal(filterLogo("device_brand", "apple"), "apple");
    assert.equal(filterLogo("source", "news.example"), null);
    assert.equal(filterLogo("country", "FR"), null);
});
