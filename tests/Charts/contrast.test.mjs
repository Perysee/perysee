import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const theme = JSON.parse(readFileSync(new URL('../../resources/js/brand-theme.json', import.meta.url)));
function rgb(value, tokens) {
    if (value.startsWith('var(')) return rgb(tokens[value.slice(4, -1)], tokens);
    if (value.startsWith('#')) return value.slice(1).match(/../g).slice(0, 3).map(v => parseInt(v, 16));
    const [, first, percent, second] = value.match(/^color-mix\(in srgb, (#[\da-f]+) ([\d.]+)%, (#[\da-f]+)\)$/i) || [];
    assert.ok(first, `Unsupported color: ${value}`);
    const weight = Number(percent) / 100;
    return rgb(first, tokens).map((c, i) => c * weight + rgb(second, tokens)[i] * (1 - weight));
}
function luminance(color) {
    const linear = color.map(v => v / 255).map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}
function check(foreground, background, tokens, minimum, label) {
    const a = luminance(rgb(foreground, tokens)), b = luminance(rgb(background, tokens));
    const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    assert.ok(ratio >= minimum, `${label}: ${ratio.toFixed(2)}:1, expected ${minimum}:1`);
}
for (const mode of ['light', 'dark']) {
    const tokens = theme.style.tokens[mode];
    test(`${mode} dashboard labels stay readable on every report surface`, () => {
        for (const text of ['--ui-text', '--ui-text-muted', '--ui-text-dimmed', '--ui-text-highlighted']) {
            for (const surface of ['--ui-bg', '--ui-bg-muted', '--ui-bg-elevated', '--ui-bg-accented', '--brand-subtle']) {
                check(tokens[text], tokens[surface], tokens, 4.5, `${text} on ${surface}`);
            }
        }
        check(tokens['--ui-text-inverted'], tokens['--ui-bg-inverted'], tokens, 7, 'Inverted selection');
    });
    test(`${mode} chart lines and active indicators remain distinct from dashboard surfaces`, () => {
        for (const surface of ['--ui-bg', '--ui-bg-elevated', '--ui-bg-muted']) {
            check(tokens['--ui-primary'], tokens[surface], tokens, 3, `Chart on ${surface}`);
        }
        check('#F8F8F8', tokens['--brand-action'] || tokens['--ui-primary'], tokens, 4.5, 'Solid action label');
        check(tokens['--ui-border-accented'], tokens['--ui-bg-elevated'], tokens, 3, 'Control boundary');
    });
}

for (const mode of ['light', 'dark']) {
    const tokens = theme.style.tokens[mode];
    test(`${mode} separates workspace, panels and floating surfaces without using control borders as dividers`, () => {
        for (const key of ['--ui-bg-workspace', '--ui-bg-panel', '--ui-bg-overlay']) {
            assert.ok(tokens[key], `Missing surface role: ${key}`);
            check(tokens['--ui-text-muted'], tokens[key], tokens, 4.5, `Secondary text on ${key}`);
        }
        const workspace = luminance(rgb(tokens['--ui-bg-workspace'], tokens));
        const panel = luminance(rgb(tokens['--ui-bg-panel'], tokens));
        const overlay = luminance(rgb(tokens['--ui-bg-overlay'], tokens));
        assert.ok(panel > workspace, 'Panels should rise above the workspace');
        assert.ok(overlay >= panel, 'Floating surfaces should not be darker than panels');
        const surface = luminance(rgb(tokens['--ui-bg-panel'], tokens));
        const divider = Math.abs(luminance(rgb(tokens['--ui-border'], tokens)) - surface);
        const control = Math.abs(luminance(rgb(tokens['--ui-border-accented'], tokens)) - surface);
        assert.ok(divider < control, 'Decorative dividers should be quieter than interactive boundaries');
    });
}
