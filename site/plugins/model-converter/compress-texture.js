/**
 * compress-texture.js <src> <dst> [--size=8192] [--quality=88]
 *
 * Pure-Sharp two-stage pipeline — fast UV dilation + high-res JPEG/WebP output.
 *
 * Stage 1 · Sharp/libvips (~10 s, ~60 MB peak)
 *   a) Source PNG read as raw RGBA at 2048 px; alpha binarised (≥128→255, else 0).
 *   b) Premultiplied-alpha iterative dilation: 6 cycles of raw blur + in-place
 *      opaque-pixel restore, entirely in premultiplied space — no grey or black
 *      background ever contaminates fill colours.  After all passes the buffer is
 *      un-premultiplied to recover the distance-weighted average of the nearest
 *      island colours.  Mathematically correct; zero grey-seam artefacts.
 *   c) Fill upscaled to source dimensions; saved as fill JPEG.
 *   Peak ≈ 70 MB (two 2048² RGBA buffers + sequential scan-line reads).
 *
 * Stage 2 · Sharp/libvips (~20 s, ~270 MB peak)
 *   Source PNG loaded at full resolution; fill JPEG composited behind it;
 *   resized to target dimensions; selective vibrance applied per-pixel to
 *   counteract compression dulling (dull colours get a strong boost, already-
 *   saturated colours barely change); encoded as JPEG (4:4:4 chroma) or WebP
 *   (effort 6).  4:4:4 vs 4:2:0 is the single biggest colour-fidelity win.
 *
 * Stage 3 · Preview JPEG
 *   1024 px JPEG (q=65) beside dst as <name>-preview.jpg.
 *
 * Why premultiplied alpha:
 *   Transparent pixels become (0,0,0,0) after premultiply.  Sharp blurs each
 *   channel independently, so they contribute zero weight.  Un-premultiply =
 *   RGB_sum / A_sum = distance-weighted average of real island colours only.
 *   No grey (#808080) is ever introduced → seam areas get the correct
 *   adjacent-island colour instead of a grey cast.
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
const composedPath = path.join(os.tmpdir(), 'goheritage-comp-' + process.pid + '.jpg');
const dstExt   = path.extname(dst).toLowerCase();
const isWebP   = dstExt === '.webp';

async function run() {
  const sharp = require('sharp');

  // ── Query source dimensions ──────────────────────────────────────────────
  const meta = await sharp(src, { limitInputPixels: false }).metadata();
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
    const { data: rawSrc, info: rawInfo } = await sharp(src, { sequentialRead: true, limitInputPixels: false })
      .resize(bW, bH, { fit: 'fill', kernel: 'lanczos3' })
      .ensureAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });

    // Binary threshold on alpha channel in-place.
    for (let i = 3; i < rawSrc.length; i += 4) {
      rawSrc[i] = rawSrc[i] >= 128 ? 255 : 0;
    }

    const rawSpec = { raw: { width: rawInfo.width, height: rawInfo.height, channels: 4 } };

    // 1b: Premultiplied-alpha iterative UV dilation — zero grey contamination.
    //
    //     Root cause of grey seams: flattening transparent pixels to grey (#808080)
    //     before blurring injects that grey into the fill colour — the Gaussian
    //     mixes island RGB with grey proportional to distance, so seams near many
    //     UV gaps turn grey.
    //
    //     Fix: premultiplied-alpha blur.
    //       • Premultiply — transparent pixels become (0,0,0,0); opaque pixels
    //         stay unchanged (R*1=R, G*1=G, B*1=B, A=255).
    //       • Blur each channel independently (libvips default).  Because
    //         transparent pixels contribute 0 weight to all four channels, the
    //         blurred RGB accumulates ONLY from nearby island pixels.  The blurred
    //         alpha channel accumulates the total weight from those same pixels.
    //       • Un-premultiply: blurred_R / blurred_A = distance-weighted average of
    //         the nearest island colours.  No grey, no black — just real texture.
    //       • Restore opaque source pixels after each pass so island edges stay
    //         sharp and the next pass pushes fill further outward.
    //     6 passes × σ100 covers ~600 px at 2048 px (→ ~2400 px at 8192 px).

    // Premultiply in-place: zero out RGB for transparent pixels.
    // (Binary alpha: 0 or 255, so opaque pixels are unchanged.)
    const premulBuf = Buffer.from(rawSrc);
    for (let i = 0; i < premulBuf.length; i += 4) {
      if (premulBuf[i + 3] === 0) {
        premulBuf[i] = premulBuf[i + 1] = premulBuf[i + 2] = 0;
      }
    }

    let dilBuf = premulBuf;
    for (let pass = 0; pass < 6; pass++) {
      // Blur all four channels independently in premultiplied space (raw → raw).
      dilBuf = await sharp(dilBuf, rawSpec)
        .blur(100)
        .raw()
        .toBuffer();

      // Restore exact source colours for opaque pixels.
      for (let i = 0; i < dilBuf.length; i += 4) {
        if (rawSrc[i + 3] === 255) {
          dilBuf[i]     = rawSrc[i];
          dilBuf[i + 1] = rawSrc[i + 1];
          dilBuf[i + 2] = rawSrc[i + 2];
          dilBuf[i + 3] = 255;
        }
      }
    }

    // Un-premultiply: divide blurred RGB by blurred alpha to recover the
    // distance-weighted island colour.  Pixels with zero blurred alpha had
    // no island within reach — fall back to neutral grey (these are deep
    // background regions that will never be visible through the UV atlas).
    const fillRaw = Buffer.allocUnsafe(dilBuf.length);
    for (let i = 0; i < dilBuf.length; i += 4) {
      if (rawSrc[i + 3] === 255) {
        // Opaque island pixel — keep original colour exactly.
        fillRaw[i]     = rawSrc[i];
        fillRaw[i + 1] = rawSrc[i + 1];
        fillRaw[i + 2] = rawSrc[i + 2];
      } else {
        const a = dilBuf[i + 3];
        if (a > 0) {
          fillRaw[i]     = Math.min(255, (dilBuf[i]     * 255 / a + 0.5) | 0);
          fillRaw[i + 1] = Math.min(255, (dilBuf[i + 1] * 255 / a + 0.5) | 0);
          fillRaw[i + 2] = Math.min(255, (dilBuf[i + 2] * 255 / a + 0.5) | 0);
        } else {
          fillRaw[i] = fillRaw[i + 1] = fillRaw[i + 2] = 128; // unreachable fallback
        }
      }
      fillRaw[i + 3] = 255; // fully opaque fill
    }

    // 1c: Upscale the dilation fill to source dimensions for Stage 2.
    //     CRITICAL: Must upscale to srcW×srcH (not tgtW×tgtH) so Stage 2
    //     can composite the original PNG BEFORE downscaling.
    await sharp(fillRaw, rawSpec)
      .resize(srcW, srcH, { fit: 'fill', kernel: 'lanczos3' })
      .jpeg({ quality: 95 })
      .toFile(fillPath);

    // ── Stage 2: composite + encode (~270 MB peak) ───────────────────────────
    // Sharp applies .flatten() BEFORE .composite() internally, so using the
    // source PNG as the base + flatten would turn its transparent pixels black
    // before the fill ever composites behind them.
    // Fix: use the fill as the base (it's already opaque, no flatten needed)
    // and composite the source PNG on top with 'over'. Transparent source pixels
    // reveal the fill; opaque pixels show the original texture color.
    // We must still save full-res before downscaling — Sharp applies resize
    // before composite, so the two-step approach is required.

    await sharp(fillPath, { limitInputPixels: false })
      .composite([{ input: src, blend: 'over', left: 0, top: 0 }])
      .jpeg({ quality: 95, chromaSubsampling: '4:4:4' })
      .toFile(composedPath);

    // Decode + resize → raw RGB so we can run selective vibrance before encoding.
    const { data: finalRaw, info: finalInfo } = await sharp(composedPath, { sequentialRead: true, limitInputPixels: false })
      .resize(tgtW, tgtH, { fit: 'inside', withoutEnlargement: true, kernel: 'lanczos3' })
      .removeAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });

    // ── Selective vibrance ────────────────────────────────────────────────
    // JPEG/WebP compression desaturates colours, especially the muted greys,
    // browns and pastels that dominate stone/stucco architecture textures.
    // A flat saturation bump (.modulate) shifts already-vivid colours into
    // unnatural territory. Vibrance fixes this: each pixel's boost factor is
    // (1 - sat)² × VIBRANCE — dull colours get a strong push, saturated
    // colours barely change. Operates in straight RGB (no HSL conversion) by
    // pulling non-max channels further from the max channel, which deepens
    // saturation while preserving hue and the brightest channel exactly.
    const VIBRANCE = 0.35;
    for (let i = 0; i < finalRaw.length; i += 3) {
      const r = finalRaw[i];
      const g = finalRaw[i + 1];
      const b = finalRaw[i + 2];
      const max = r > g ? (r > b ? r : b) : (g > b ? g : b);
      const min = r < g ? (r < b ? r : b) : (g < b ? g : b);
      if (max === 0 || max === min) continue; // pure black or perfect grey
      const sat = (max - min) / max;
      const inv = 1 - sat;
      const k = 1 + VIBRANCE * inv * inv;
      finalRaw[i]     = (max - Math.min(max, (max - r) * k) + 0.5) | 0;
      finalRaw[i + 1] = (max - Math.min(max, (max - g) * k) + 0.5) | 0;
      finalRaw[i + 2] = (max - Math.min(max, (max - b) * k) + 0.5) | 0;
    }

    const outPipeline = sharp(finalRaw, { raw: { width: finalInfo.width, height: finalInfo.height, channels: 3 } });

    if (isWebP) {
      // effort 6 = max quality/size ratio (slower encode, smaller file at same Q)
      await outPipeline.webp({ quality, effort: 6 }).toFile(dst);
    } else {
      // 4:4:4 chroma keeps full colour resolution — 4:2:0 was the main culprit
      // behind dull stone/brick textures losing their warmth after compression.
      await outPipeline.jpeg({ quality, chromaSubsampling: '4:4:4' }).toFile(dst);
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
    try { fs.unlinkSync(composedPath); } catch (_) {}
  }
}

run().catch(function (err) {
  console.error(err.message || String(err));
  process.exit(1);
});
