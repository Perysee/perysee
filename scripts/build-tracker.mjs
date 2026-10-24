import { build } from 'vite';
import { gzipSync } from 'node:zlib';
import { readFile, writeFile } from 'node:fs/promises';

await build({
    configFile: false,
    publicDir: false,
    build: {
        outDir: 'public/tracker', emptyOutDir: false, minify: true,
        lib: { entry: 'resources/js/lueur.js', name: 'Lueur', formats: ['iife'], fileName: () => 'lueur.v1.js' },
    },
});
const source = await readFile('public/tracker/lueur.v1.js');
const gzip = gzipSync(source, { level: 9 });
if (gzip.byteLength >= 5120) throw new Error('Lueur must stay below 5 KiB gzip.');
await writeFile('public/tracker/lueur.v1.js.gz', gzip);
console.log(`Lueur: ${source.byteLength} bytes minified, ${gzip.byteLength} bytes gzip.`);
