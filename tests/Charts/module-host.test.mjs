import { test } from "node:test";
import assert from "node:assert/strict";
import { createModuleHost } from "../../resources/js/module-host.mjs";

function fakeDocument() {
    const attached = [];
    return {
        attached,
        createElement: (tag) => ({ tag }),
        head: {
            appendChild(element) {
                attached.push(element);
                queueMicrotask(() =>
                    (element.src || element.href).includes("broken")
                        ? element.onerror?.(new Error("network"))
                        : element.onload?.(),
                );
            },
        },
    };
}

test("a module registers a screen under the module: namespace", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });
    const component = { name: "Hello" };

    host.registerScreen("module:hello", component);

    assert.equal(host.screens["module:hello"], component);
});

test("screens outside the module: namespace cannot be registered, so a module cannot replace a core screen", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });

    assert.throws(() => host.registerScreen("dashboard", {}), /module:/);
    assert.throws(() => host.registerScreen("module:", {}), /module:/);
    assert.equal(host.screens.dashboard, undefined);
});

test("a screen cannot be registered twice", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });
    host.registerScreen("module:hello", {});

    assert.throws(() => host.registerScreen("module:hello", {}), /already/);
});

test("scripts and styles are attached once even when requested repeatedly", async () => {
    const document = fakeDocument();
    const host = createModuleHost({ document, globals: {} });
    const modules = { scripts: ["/a.js?v=1"], styles: ["/a.css?v=1"] };

    await host.load(modules);
    await host.load(modules);

    assert.deepEqual(
        document.attached.map((element) => [element.tag, element.src || element.href]),
        [
            ["link", "/a.css?v=1"],
            ["script", "/a.js?v=1"],
        ],
    );
    assert.equal(document.attached[0].rel, "stylesheet");
});

test("a script that fails to load is reported without blocking the others", async () => {
    const document = fakeDocument();
    const host = createModuleHost({ document, globals: {} });

    const failures = await host.load({ scripts: ["/broken.js", "/fine.js"], styles: [] });

    assert.deepEqual(failures, ["/broken.js"]);
    assert.equal(document.attached.length, 2);
});

test("a failed script can be retried on the next load", async () => {
    const document = fakeDocument();
    const host = createModuleHost({ document, globals: {} });

    await host.load({ scripts: ["/broken.js"], styles: [] });
    await host.load({ scripts: ["/broken.js"], styles: [] });

    assert.equal(document.attached.length, 2);
});

test("the host exposes itself and the shared libraries on the global scope for compiled modules", () => {
    const globals = {};
    const libraries = { Vue: { v: 1 }, Inertia: { i: 1 } };

    const host = createModuleHost({ document: fakeDocument(), globals, libraries });

    assert.equal(globals.Perysee.registerScreen, host.registerScreen);
    assert.equal(globals.Perysee.Vue, libraries.Vue);
    assert.equal(globals.Perysee.Inertia, libraries.Inertia);
});

test("a module adds a component to a named slot of an existing screen", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });
    const section = { name: "Section" };

    host.registerSlot("settings.sections", section);

    assert.deepEqual(host.slots["settings.sections"].map((entry) => entry.component), [section]);
});

test("slot components are ordered by their order then by registration", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });
    const [a, b, c] = [{ n: "a" }, { n: "b" }, { n: "c" }];

    host.registerSlot("settings.sections", a, { order: 20 });
    host.registerSlot("settings.sections", b, { order: 10 });
    host.registerSlot("settings.sections", c, { order: 20 });

    assert.deepEqual(host.slots["settings.sections"].map((entry) => entry.component), [b, a, c]);
});

test("slot names must be lowercase dotted identifiers", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });

    for (const name of ["settings", "Settings.Sections", "settings..sections", "settings.sections;", ""]) {
        assert.throws(() => host.registerSlot(name, {}), /slot/i, name);
    }
});

test("registerSlot is available to compiled modules", () => {
    const globals = {};
    const host = createModuleHost({ document: fakeDocument(), globals });

    assert.equal(globals.Perysee.registerSlot, host.registerSlot);
});

test("a module registers the component of its own settings page, one per module", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });
    const page = { name: "Settings" };

    host.registerSettings("white-label", page);

    assert.equal(host.settingsPages["white-label"], page);
    assert.throws(() => host.registerSettings("white-label", {}), /already/);
});

test("settings pages are keyed by a safe module id", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });

    for (const id of ["", "../x", "White", "a b", "-x"]) {
        assert.throws(() => host.registerSettings(id, {}), /module id/i, id);
    }
});

test("registerSettings is available to compiled modules", () => {
    const globals = {};
    const host = createModuleHost({ document: fakeDocument(), globals });

    assert.equal(globals.Perysee.registerSettings, host.registerSettings);
});

test("a slot component remembers its module so the page can hide it where the module is not active", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });

    host.registerSlot("site.overview.after", { n: 1 }, { module: "annotations" });
    host.registerSlot("site.overview.after", { n: 2 });

    assert.deepEqual(host.slots["site.overview.after"].map((entry) => entry.module), ["annotations", undefined]);
});

test("slots only show components of modules active in the current page", () => {
    const host = createModuleHost({ document: fakeDocument(), globals: {} });
    const [a, b, c] = [{ n: "a" }, { n: "b" }, { n: "c" }];
    host.registerSlot("site.overview.after", a, { module: "annotations" });
    host.registerSlot("site.overview.after", b, { module: "other" });
    host.registerSlot("site.overview.after", c);

    assert.deepEqual(host.visibleSlot("site.overview.after", ["annotations"]), [a, c]);
    assert.deepEqual(host.visibleSlot("site.overview.after", []), [c]);
    assert.deepEqual(host.visibleSlot("unknown.slot", ["annotations"]), []);
});
