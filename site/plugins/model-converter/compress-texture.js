/**
 * compress-texture.js <src> <dst>
 *
 * 1. UV dilation: Gaussian blur the image, composite original on top so
 *    transparent/empty UV space gets filled with bleed colour (no black seams).
 *    Crucially, this MUST be done before resizing to prevent black edge interpolation!
 * 2. Resize to max 4096 px on longest side.
 * 3. Save as JPEG quality 85.
 */

const path   = require('path');
const sharp  = require(path.join(__dirname, '../../../node_modules/sharp'));

const [,, src, dst] = process.argv;
if (!src || !dst) { console.error('usage: node compress-texture.js <src> <dst> [--size=4096] [--quality=85]'); process.exit(1); }

const sizeArg    = process.argv.find(a => a.startsWith('--size='));
const qualityArg = process.argv.find(a => a.startsWith('--quality='));
const maxSize    = sizeArg    ? parseInt(sizeArg.split('=')[1])    : 4096;
const quality    = qualityArg ? parseInt(qualityArg.split('=')[1]) : 85;

(async () => {
  try {
    const img = sharp(src).ensureAlpha();
    const { width, height } = await img.metadata();

    // Blur a copy for UV dilation fill
    const blurred = await sharp(src)
      .ensureAlpha()
      .blur(24)
      .raw()
      .toBuffer({ resolveWithObject: true });

    const original = await sharp(src)
      .ensureAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });

    const { width: w, height: h, channels } = original.info;
    const orig = original.data;
    const blur = blurred.data;
    const out  = Buffer.alloc(orig.length);

    // Composite: where alpha > 10 use original, else use blurred fill.
    // Done PRE-resize. If done post-resize, the interpolator bleeds black into semi-transparent edges, ruining the seams.
    for (let i = 0; i < orig.length; i += channels) {
      const a = orig[i + channels - 1];
      if (a > 10) {
        out.set(orig.slice(i, i + channels), i);
      } else {
        out.set(blur.slice(i, i + channels), i);
        out[i + channels - 1] = 255; // make opaque
      }
    }

    await sharp(out, { raw: { width: w, height: h, channels } })
      .resize(maxSize, maxSize, { fit: 'inside', withoutEnlargement: true })
      .flatten({ background: { r: 0, g: 0, b: 0 } }) // drop alpha for JPEG
      .jpeg({ quality, mozjpeg: false })
      .toFile(dst);

    console.log('ok');
  } catch (e) {
    console.error(e.message);
    process.exit(1);
  }
})();
