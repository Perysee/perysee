import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync, existsSync } from "node:fs";

const root = new URL("../../", import.meta.url);
const read = (path) => readFileSync(new URL(path, root), "utf8");

// Nuxt UI components are imported at compile time, so the application does not register them globally.
// A module reaches them with resolveComponent("UCard"), which only works if the application registers them.
const registered = () => {
    assert.ok(existsSync(new URL("resources/js/module-components.js", root)), "the application must expose Nuxt UI components to modules");
    return [...read("resources/js/module-components.js").matchAll(/^\s+(U[A-Za-z]+),?$/gm)].map((match) => match[1]);
};

const used = () => {
    // The official modules live in Perysee/perysee-modules and are only present when checked out here.
    const sources = (existsSync(new URL("modules/", root)) ? readdirSync(new URL("modules/", root), { withFileTypes: true }) : [])
        .filter((entry) => entry.isDirectory() || entry.isSymbolicLink())
        .map((entry) => `modules/${entry.name}/dist/module.js`)
        .filter((path) => existsSync(new URL(path, root)));
    sources.push("resources/module-template/dist/module.js.stub");

    return sources.flatMap((path) => [...read(path).matchAll(/resolveComponent\("(U[A-Za-z]+)"\)/g)].map((match) => [path, match[1]]));
};

test("every component a shipped module asks for is registered by the application", () => {
    const names = registered();

    for (const [path, name] of used()) {
        assert.ok(names.includes(name), `${path} uses ${name}, which the application does not register`);
    }
});

test("the application registers the module components on its Vue app", () => {
    const app = read("resources/js/app.js");

    assert.match(app, /registerModuleComponents\(app\)/);
});

test("the registry only lists real Nuxt UI components", () => {
    for (const name of registered()) {
        assert.ok(existsSync(new URL(`node_modules/@nuxt/ui/dist/runtime/components/${name.slice(1)}.vue`, root)), `${name} is not a Nuxt UI component`);
    }
});
