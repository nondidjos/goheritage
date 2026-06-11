// Production JS build for GoHéritage.
//
// Minifies every browser-facing script in assets/js/ to a sibling
// <name>.min.js, comments stripped. We do NOT bundle: each file keeps its
// own `import 'three'` / `import 'three/addons/…'` bare specifiers so the
// page's importmap still resolves them (three.js is never inlined).
//
// Scope: assets/js/ only. The Kirby panel plugins (site/plugins/*/index.js)
// and the Node CLI scripts they shell out to are intentionally left alone —
// the CLIs run on the server (minifying only hurts debugging), and the panel
// is admin-only.
//
// Run: npm run build:js   (invoked by `npm run build`, which deploy.sh runs)

import { build } from 'esbuild';
import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const jsDir = join(root, 'assets', 'js');

const sources = readdirSync(jsDir).filter(
  (f) => f.endsWith('.js') && !f.endsWith('.min.js')
);

if (sources.length === 0) {
  console.log('[build-js] no sources found in assets/js');
  process.exit(0);
}

let failed = false;
await Promise.all(
  sources.map(async (file) => {
    const out = file.replace(/\.js$/, '.min.js');
    try {
      await build({
        entryPoints: [join(jsDir, file)],
        outfile: join(jsDir, out),
        minify: true,
        bundle: false,          // keep imports external (importmap resolves them)
        legalComments: 'none',  // strip every comment, including licenses
        logLevel: 'warning',
        sourcemap: false,
        charset: 'utf8',
      });
      console.log(`[build-js] ${file} -> ${out}`);
    } catch (err) {
      failed = true;
      console.error(`[build-js] FAILED ${file}:`, err.message);
    }
  })
);

process.exit(failed ? 1 : 0);
