/**
 * generate-tiles.js <src> <dst-prefix>
 *
 *   <src>         absolute path to a source — raster image OR PDF
 *   <dst-prefix>  output base path WITHOUT extension; we let libvips append
 *                 `.dzi` for the manifest and create a sibling
 *                 `<dst-prefix>_files/` directory for the tile pyramid.
 *
 * Raster (JPG / PNG / WebP / TIFF):
 *   Direct Sharp/libvips tile() pipeline. No intermediate steps.
 *
 * PDF:
 *   1. pdftoppm rasterises page 1 to a high-resolution PNG (default 250 DPI
 *      → ~2000 px wide for an A4 plan, plenty for zoom + sharp text).
 *   2. The resulting PNG is fed into the same Sharp tile pipeline so output
 *      and viewer code don't need to know whether the source was PDF.
 *   3. Multi-page PDFs: we only tile page 1 here. Multi-page support is
 *      better handled at the plugin level (one .dzi per page) which we'll
 *      add when the first multi-page plan shows up.
 *
 * Lock file (~/goheritage-tile.lock) serialises tile generation across
 * concurrent uploads so the server isn't crushed.
 */

const path = require('path');
const fs   = require('fs');
const os   = require('os');
const { execFileSync } = require('child_process');

const [,, src, dstPrefix] = process.argv;
if (!src || !dstPrefix) {
  console.error('usage: node generate-tiles.js <src> <dst-prefix>');
  process.exit(1);
}

const lockFile = path.join(os.tmpdir(), 'goheritage-tile.lock');

async function main() {
  const start = Date.now();
  const timeout = 5 * 60 * 1000;
  while (fs.existsSync(lockFile)) {
    if (Date.now() - start > timeout) {
      console.error('lock timeout — another tile job is stuck');
      process.exit(2);
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  fs.writeFileSync(lockFile, String(process.pid));

  let pdfTempPng = null;
  try {
    const sharp = require('sharp');

    // ── PDF → PNG pre-step ──────────────────────────────────────────
    let imageSrc = src;
    const ext = path.extname(src).toLowerCase();
    if (ext === '.pdf') {
      // pdftoppm writes <prefix>-<page>.png (note: page numbers, not zero-padded
      // by default, so a 1-page PDF gives <prefix>-1.png).
      pdfTempPng = path.join(os.tmpdir(), 'goheritage-pdf-' + process.pid);
      try {
        execFileSync('pdftoppm', [
          '-r',  '250',          // 250 DPI — sharp enough for A0 plans at 6×
          '-f',  '1',            // first page
          '-l',  '1',            // last page (only render page 1 for now)
          '-png',
          src,
          pdfTempPng,            // output prefix
        ], { stdio: ['ignore', 'pipe', 'pipe'] });
      } catch (e) {
        throw new Error('pdftoppm failed: ' + e.message);
      }
      // pdftoppm appends -1.png (or -01.png if there are 10+ pages)
      const candidates = [pdfTempPng + '-1.png', pdfTempPng + '-01.png'];
      imageSrc = candidates.find(fs.existsSync);
      if (!imageSrc) {
        throw new Error('pdftoppm produced no output (tried ' + candidates.join(', ') + ')');
      }
    }

    const meta = await sharp(imageSrc, { limitInputPixels: false }).metadata();
    if (!meta.width || !meta.height) {
      throw new Error('could not read image dimensions from ' + imageSrc);
    }

    // ── Sharp/libvips DZI output ────────────────────────────────────
    //   • destination path takes NO extension — libvips appends `.dzi`
    //     and creates a sibling `<base>_files/` directory.
    //   • Chaining `.jpeg()` / `.png()` after `.tile()` overrides the
    //     tile writer entirely (single image out), so we DON'T chain a
    //     format. Tiles ship as JPEG by default; libvips records the
    //     actual format in the .dzi manifest's Format attribute so the
    //     viewer can pick the right URL.
    await sharp(imageSrc, { sequentialRead: true, limitInputPixels: false })
      .tile({
        size:      256,
        overlap:   2,
        layout:    'dz',
        container: 'fs',
      })
      .toFile(dstPrefix);

    console.log('ok (' + meta.width + 'x' + meta.height + (ext === '.pdf' ? ' from PDF page 1' : '') + ')');
  } finally {
    try { fs.unlinkSync(lockFile); } catch (_) {}
    if (pdfTempPng) {
      // Clean up the intermediate PNG(s) — match either -1.png or -01.png
      for (const f of fs.readdirSync(os.tmpdir())) {
        if (f.startsWith(path.basename(pdfTempPng) + '-') && f.endsWith('.png')) {
          try { fs.unlinkSync(path.join(os.tmpdir(), f)); } catch (_) {}
        }
      }
    }
  }
}

main().catch((err) => {
  console.error(err.message || String(err));
  try { fs.unlinkSync(lockFile); } catch (_) {}
  process.exit(1);
});
