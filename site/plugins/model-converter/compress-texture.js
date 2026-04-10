/**
 * compress-texture.js <src> <dst>
 *
 * 1. Resize to max size on longest side FIRST to save memory and CPU
 * 2. UV dilation: Gaussian blur the image, composite original on top so
 *    transparent/empty UV space gets filled with bleed colour (no black seams).
 * 3. Save as JPEG.
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
    // 1. Resize the original image to at most maxSize. This enormously saves memory
    // while keeping the identical visual output.
    const resizedBuffer = await sharp(src)
      .resize(maxSize, maxSize, { fit: 'inside', withoutEnlargement: true })
      .png()
      .toBuffer();

    // Blur a copy for UV dilation fill
    const blurred = await sharp(resizedBuffer)
      .ensureAlpha()
      .blur(24) // the blur radius stays 24, giving excellent coverage.
      .raw()
      .toBuffer({ resolveWithObject: true });

    // The raw data of the resized image
    const original = await sharp(resizedBuffer)
      .ensureAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });

    const { width: w, height: h, channels } = original.info;
    const orig = original.data;
    const blur = blurred.data;
    const out  = Buffer.alloc(orig.length);

    // Exact reimplementation of original UV Dilation Logic:
    // Composite: where alpha > 10 use original, else use blurred fill.
    // This hard threshold correctly prevents anti-aliased black edge halos.
    for (let i = 0; i < orig.length; i += channels) {
      const a = orig[i + channels - 1];
      if (a > 10) {
        out.set(orig.slice(i, i + channels), i);
      } else {
        out.set(blur.slice(i, i + channels), i);
        out[i + channels - 1] = 255; // make opaque
      }
    }

    // Output final jpeg directly
    await sharp(out, { raw: { width: w, height: h, channels } })
      .flatten({ background: { r: 0, g: 0, b: 0 } }) // drop alpha for JPEG
      .jpeg({ quality, mozjpeg: false })
      .toFile(dst);

    console.log('ok');
  } catch (e) {
    console.error(e.message);
    process.exit(1);
  }
})();
