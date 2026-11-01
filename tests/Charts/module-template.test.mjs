import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import * as Vue from "vue";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createModuleHost } from "../../resources/js/module-host.mjs";

const stub = new URL("../../resources/module-template/dist/module.js.stub", import.meta.url);

function compiledScreen(id, name) {
    const source = readFileSync(stub, "utf8").replaceAll("{{id}}", id).replaceAll("{{name}}", name);
    const globals = { Perysee: null };
    const host = createModuleHost({ document: {}, globals, libraries: { Vue } });
    vm.runInNewContext(source, globals);

    return host.screens[`module:${id}`];
}

test("the template screen registers itself through the global host and renders with the page props", async () => {
    const screen = compiledScreen("hello", "Hello");
    const UCard = { render() { return h("section", this.$slots.default()); } };
    const app = createSSRApp({
        render: () => h(screen, { greeting: "Hello from Hello.", auth: { id: 1 }, links: {}, screen: "module:hello" }),
    });
    app.component("UCard", UCard);

    const html = await renderToString(app);

    assert.match(html, /<h1[^>]*>Hello<\/h1>/);
    assert.match(html, /Hello from Hello\./);
    assert.doesNotMatch(html, /object Object/, "page props must not leak onto the DOM as attributes");
});

test("the template screen only claims its own module: namespace", () => {
    const screen = compiledScreen("pdf-export", "PDF export");

    assert.ok(screen);
    assert.equal(screen.inheritAttrs, false);
});
