/**
 * compress-texture.js <src> <dst> [--size=8192] [--quality=88]
 *
 * Pure-Sharp two-stage pipeline — fast UV dilation + high-res JPEG output.
 *
 * Stage 1 · Sharp/libvips (~5 s, ~60 MB peak)
 *   a) Source PNG read sequentially at 2048 px; transparent pixels replaced
 *      with #808080 via flatten; Gaussian-blur σ4 → 2048×2048 JPEG buffer.
 *   b) Source PNG read sequentially at 2048 px, RGBA preserved → PNG buffer.
 *   c) RGBA source composited OVER blur-fill at 2048; flattened; upscaled to
 *      target dims; saved as fill JPEG.
 *   Peak ≈ 60 MB (two 2048² buffers + sequential scan-line reads).
 *
 * Stage 2 · Sharp/libvips (~20 s, ~270 MB peak)
 *   Source PNG loaded at full resolution; fill JPEG composited behind it;
 *   output encoded as JPEG.  libvips uses sequential access for the fill JPEG
 *   so only ~3 MB of fill is in RAM at once.  Peak is the PNG decode (~256 MB).
 *
 * Combined: ~25 s vs the old 70–300 s single-pass approach.
 *
 * UV-dilation colour fix:
 *   Transparent PNG pixels are (0,0,0,0).  Blurring RGBA mixes those black
 *   values into fill colours.  Fix: flatten with #808080 background before
 *   blurring so transparent regions become grey, not black.
 */

const path = require('path');
const os   = require('os');
const fs   = require('fs');

const [,, src, dst] = process.argv;
if (!src || !dst) {
  console.error('usage: node compress-texture.js <src> <dst> [--size=8192] [--quality=88]');
  process.exit(1);
}

const sizeArg    = process.argv.find(a => a.startsWith('--size='));
const qualityArg = process.argv.find(a => a.startsWith('--quality='));
const MAX_SUPPORTED = 8192;
const requestedSize = sizeArg    ? parseInt(sizeArg.split('=')[1])    : 8192;
const maxSize       = Math.min(requestedSize, MAX_SUPPORTED);
const quality       = qualityArg ? parseInt(qualityArg.split('=')[1]) : 88;

if (requestedSize > MAX_SUPPORTED) {
  process.stderr.write('[compress-texture] size capped at ' + MAX_SUPPORTED + '\n');
}

const fillPath = path.join(os.tmpdir(), 'goheritage-fill-' + process.pid + '.jpg');

async function run() {
  const sharp = require('sharp');

  // ── Query source dimensions ──────────────────────────────────────────────
  const meta = await sharp(src).metadata();
  const srcW = meta.width;
  const srcH = meta.height;
  if (!srcW || !srcH) throw new Error('Could not read source dimensions from ' + src);

  // Target dimensions — scale down to fit within maxSize, never upscale.
  const scale = Math.min(maxSize / srcW, maxSize / srcH, 1);
  const tgtW  = Math.round(srcW * scale);
  const tgtH  = Math.round(srcH * scale);

  // Blur canvas — UV dilation at ≤2048 px gives identical fill coverage after
  // upscaling, but reads only a fraction of the source into RAM.
  const BLUR_SIZE = 2048;
  const bScale    = Math.min(BLUR_SIZE / tgtW, BLUR_SIZE / tgtH, 1);
  const bW        = Math.round(tgtW * bScale);
  const bH        = Math.round(tgtH * bScale);

  try {
    // ── Stage 1: UV-dilation fill (pure Sharp, ~60 MB peak) ─────────────────

    // 1a: Grey-fill + Gaussian blur σ4 at 2048 px.
    //     sequentialRead → only a few scan lines of the source PNG in RAM.
    const blurBuf = await sharp(src, { sequentialRead: true })
      .resize(bW, bH, { fit: 'fill', kernel: 'lanczos3' })
      .flatten({ background: { r: 128, g: 128, b: 128 } })
      .blur(4)
      .jpeg({ quality: 90 })
      .toBuffer();

    // 1b: Source at 2048 px with alpha preserved.
    //     Second sequential pass through the source PNG (~16 MB RGBA result).
    const srcSmallBuf = await sharp(src, { sequentialRead: true })
      .resize(bW, bH, { fit: 'fill', kernel: 'lanczos3' })
      .png()
      .toBuffer();

    // 1c: Composite RGBA source OVER blur-fill; flatten; upscale to final dims.
    //     Both inputs are tiny (~12–16 MB each).  The upscale from bW→tgtW
    //     is written strip-by-strip so the JPEG output doesn't need a full
    //     8192² RGBA buffer in RAM.
    await sharp(blurBuf)
      .composite([{ input: srcSmallBuf, blend: 'over', left: 0, top: 0 }])
      .flatten({ background: { r: 0, g: 0, b: 0 } })
      .resize(tgtW, tgtH, { fit: 'fill', kernel: 'lanczos3' })
      .jpeg({ quality: 85 })
      .toFile(fillPath);

    // ── Stage 2: composite + encode (~270 MB peak) ───────────────────────────
    // dest-over: main pipeline image (source PNG with alpha) placed OVER the
    // composite input (fill).
    //   • Opaque UV-island pixels → source PNG colour (full resolution)
    //   • Transparent background → fill colour (UV-dilated, seam-free)
    // libvips reads fillPath sequentially (~3 MB at a time).  Peak is the
    // decoded source PNG (~256 MB RGBA).
    await sharp(src, { sequentialRead: true })
      .resize(tgtW, tgtH, { fit: 'inside', withoutEnlargement: true, kernel: 'lanczos3' })
      .composite([{ input: fillPath, blend: 'dest-over', left: 0, top: 0 }])
      .flatten({ background: { r: 0, g: 0, b: 0 } })
      .jpeg({ quality, chromaSubsampling: '4:2:0' })
      .toFile(dst);

    console.log('ok (' + tgtW + 'x' + tgtH + ')');

  } finally {
    try { fs.unlinkSync(fillPath); } catch (_) {}
  }
}

run().catch(function (err) {
  console.error(err.message || String(err));
  process.exit(1);
});
