/**
 * compress-texture.js <src> <dst> [--size=8192] [--quality=88]
 *
 * Pure-Sharp two-stage pipeline — fast UV dilation + high-res JPEG/WebP output.
 *
 * Stage 1 · Sharp/libvips (~5 s, ~60 MB peak)
 *   a) Source PNG read as raw RGBA at 2048 px; alpha channel binarised (≥128→255,
 *      else 0) to prevent semi-transparent UV-island edges from mixing with the
 *      grey background during flatten — the exact behaviour of IM's
 *      "-channel alpha -threshold 50% +channel".
 *   b) Thresholded raw buffer flattened with #808080, Gaussian-blurred σ4 →
 *      2048×2048 JPEG buffer.
 *   c) Thresholded raw buffer composited OVER blur-fill; flattened; upscaled to
 *      target dims; saved as fill JPEG.
 *   Peak ≈ 60 MB (one 2048² RGBA buffer + sequential scan-line reads).
 *
 * Stage 2 · Sharp/libvips (~20 s, ~270 MB peak)
 *   Source PNG loaded at full resolution; fill JPEG composited behind it;
 *   output encoded as JPEG/WebP.  libvips uses sequential access for the fill
 *   JPEG so only ~3 MB of fill is in RAM at once.  Peak is the PNG decode (~256 MB).
 *
 * Stage 3 · Preview JPEG
 *   1024 px JPEG (q=65) generated beside dst as <name>-preview.jpg for
 *   progressive loading in the Three.js viewer.
 *
 * Combined: ~25 s vs the old 70–300 s single-pass approach.
 *
 * UV-dilation colour fix:
 *   Transparent PNG pixels are (0,0,0,0).  Blurring RGBA mixes those black
 *   values into fill colours.  Fix 1: flatten with #808080 background before
 *   blurring so transparent regions become grey, not black.  Fix 2 (THIS PATCH):
 *   binarise the alpha channel *before* flatten so semi-transparent edge pixels
 *   (α 1-254) are treated as either fully opaque or fully transparent — they no
 *   longer tint the grey fill with their partial RGB contribution.
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
const dstExt   = path.extname(dst).toLowerCase();
const isWebP   = dstExt === '.webp';

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

    // 1a: Load source as raw RGBA at blur resolution (single sequential pass).
    //     Then binarise the alpha channel: any pixel with α ≥ 128 becomes
    //     fully opaque (255), anything below becomes fully transparent (0).
    //     This matches IM's "-channel alpha -threshold 50% +channel" and
    //     prevents semi-transparent UV-island edges from staining the grey fill.
    const { data: rawSrc, info: rawInfo } = await sharp(src, { sequentialRead: true })
      .resize(bW, bH, { fit: 'fill', kernel: 'lanczos3' })
      .ensureAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });

    // Binary threshold on alpha channel in-place.
    for (let i = 3; i < rawSrc.length; i += 4) {
      rawSrc[i] = rawSrc[i] >= 128 ? 255 : 0;
    }

    const rawSpec = { raw: { width: rawInfo.width, height: rawInfo.height, channels: 4 } };

    // 1b: Grey-fill + Gaussian blur σ4 at 2048 px from thresholded source.
    //     flatten replaces transparent (α=0) pixels with #808080 only.
    const blurBuf = await sharp(rawSrc, rawSpec)
      .flatten({ background: { r: 128, g: 128, b: 128 } })
      .blur(4)
      .jpeg({ quality: 90 })
      .toBuffer();

    // 1c: Composite thresholded RGBA source OVER blur-fill; flatten; upscale.
    //     Both inputs are tiny (~12–16 MB each).
    await sharp(blurBuf)
      .composite([{ input: rawSrc, ...rawSpec, blend: 'over', left: 0, top: 0 }])
      .flatten({ background: { r: 0, g: 0, b: 0 } })
      .resize(tgtW, tgtH, { fit: 'fill', kernel: 'linear' })
      .jpeg({ quality: 85 })
      .toFile(fillPath);

    // ── Stage 2: composite + encode (~270 MB peak) ───────────────────────────
    // dest-over: main pipeline image (source PNG with alpha) placed OVER the
    // composite input (fill).
    //   • Opaque UV-island pixels → source PNG colour (full resolution)
    //   • Transparent background → fill colour (UV-dilated, seam-free)
    // libvips reads fillPath sequentially (~3 MB at a time).  Peak is the
    // decoded source PNG (~256 MB RGBA).
    const pipeline = sharp(src, { sequentialRead: true })
      .resize(tgtW, tgtH, { fit: 'inside', withoutEnlargement: true, kernel: 'lanczos3' })
      .composite([{ input: fillPath, blend: 'dest-over', left: 0, top: 0 }])
      .flatten({ background: { r: 0, g: 0, b: 0 } });
    if (isWebP) {
      await pipeline.webp({ quality, effort: 4 }).toFile(dst);
    } else {
      await pipeline.jpeg({ quality, chromaSubsampling: '4:2:0' }).toFile(dst);
    }

    // ── Stage 3: 1024 px JPEG preview for progressive loading ────────────────
    // Saved beside dst as <name>-preview.jpg.  Always JPEG (fast decode, all
    // browsers) regardless of the main output format.  Only generated when the
    // output is wider than 1024 px — below that it would be an enlargement.
    if (tgtW > 1024 || tgtH > 1024) {
      const previewDst = dst.replace(/(\.[^.]+)$/, '-preview.jpg');
      await sharp(dst, { sequentialRead: true })
        .resize(1024, 1024, { fit: 'inside', withoutEnlargement: true, kernel: 'lanczos3' })
        .jpeg({ quality: 65, chromaSubsampling: '4:2:0' })
        .toFile(previewDst);
    }

    console.log('ok (' + tgtW + 'x' + tgtH + ')');

  } finally {
    try { fs.unlinkSync(fillPath); } catch (_) {}
  }
}

run().catch(function (err) {
  console.error(err.message || String(err));
  process.exit(1);
});
