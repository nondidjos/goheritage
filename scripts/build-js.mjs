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
import { readdirSync, mkdirSync, copyFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const jsDir = join(root, 'assets', 'js');

// The COPC viewer decodes LAZ in the browser via laz-perf's WASM. Copy it out
// of node_modules into a served location (gitignored, regenerated here) so the
// emscripten glue can fetch it at runtime via its locateFile override.
const wasmSrc = join(root, 'node_modules', 'laz-perf', 'lib', 'web', 'laz-perf.wasm');
if (existsSync(wasmSrc)) {
  const wasmDir = join(root, 'assets', 'wasm');
  mkdirSync(wasmDir, { recursive: true });
  copyFileSync(wasmSrc, join(wasmDir, 'laz-perf.wasm'));
  console.log('[build-js] copied laz-perf.wasm -> assets/wasm/');
} else {
  console.warn('[build-js] laz-perf.wasm not found — COPC viewer will not work (run npm install)');
}

const sources = readdirSync(jsDir).filter(
  (f) => f.endsWith('.js') && !f.endsWith('.min.js')
);

if (sources.length === 0) {
  console.log('[build-js] no sources found in assets/js');
  process.exit(0);
}

// Entry points that must be BUNDLED rather than just minified, so their
// relative/CJS imports get inlined. three.js stays external in both so it
// still resolves through the page importmap.
//   - copc-viewer.js   pulls in copc + laz-perf (CommonJS) and ./pointcloud-common.js
//   - pointcloud-viewer.js pulls in ./pointcloud-common.js
// pointcloud-common.js is only ever imported by those two (never loaded on its
// own), so it isn't an entry point — skip emitting a standalone min for it.
const BUNDLED = new Set(['copc-viewer.js', 'pointcloud-viewer.js']);
const SKIP = new Set(['pointcloud-common.js']);

let failed = false;
await Promise.all(
  sources.map(async (file) => {
    if (SKIP.has(file)) return;          // inlined into the bundles, no standalone min
    const out = file.replace(/\.js$/, '.min.js');
    const bundle = BUNDLED.has(file);
    try {
      await build({
        entryPoints: [join(jsDir, file)],
        outfile: join(jsDir, out),
        minify: true,
        bundle,
        format: bundle ? 'esm' : undefined,
        platform: bundle ? 'browser' : undefined,
        // Keep three resolving via the importmap even in the bundled file.
        external: bundle ? ['three', 'three/addons/*'] : undefined,
        legalComments: 'none',  // strip every comment, including licenses
        logLevel: 'warning',
        sourcemap: false,
        charset: 'utf8',
      });
      console.log(`[build-js] ${file} -> ${out}${bundle ? ' (bundled)' : ''}`);
    } catch (err) {
      failed = true;
      console.error(`[build-js] FAILED ${file}:`, err.message);
    }
  })
);

process.exit(failed ? 1 : 0);
