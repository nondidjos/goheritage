/**
 * thumb.js  <src> <dst> <width> <height> <crop:0|1> <quality> <format>
 *
 * Memory-safe thumbnail generator for the Kirby `thumb` component override
 * (see goheritage-core/index.php). Uses Sharp/libvips, which streams the source
 * instead of decoding it whole into memory like GD does — the difference
 * between a few tens of MB and OOM when resizing a 14 MB JPEG / 100 MB PNG on
 * the 450 MB box.
 *
 * Writes to <dst>.tmp then renames, so a concurrent request never serves a
 * half-written file.
 */
const sharp = require('sharp');
const fs = require('fs');

const [, , src, dst, wStr, hStr, cropStr, qStr, fmt] = process.argv;
const w = parseInt(wStr, 10) || null;
const h = parseInt(hStr, 10) || null;
const crop = cropStr === '1';
const quality = parseInt(qStr, 10) || 80;
const format = (fmt || '').toLowerCase();

(async () => {
  if (!src || !dst) throw new Error('usage: thumb.js src dst w h crop q fmt');

  let img = sharp(src, { failOn: 'none', limitInputPixels: false, sequentialRead: true })
    .rotate(); // honour EXIF orientation before resizing

  if (w || h) {
    img = img.resize({
      width: w || null,
      height: h || null,
      fit: (crop && w && h) ? 'cover' : 'inside',
      position: 'centre',
      withoutEnlargement: !crop, // don't upscale on plain resize; cover may need to fill
    });
  }

  if (format === 'webp')      img = img.webp({ quality });
  else if (format === 'avif') img = img.avif({ quality });
  else if (format === 'png')  img = img.png({ compressionLevel: 8 });
  else if (format === 'gif')  img = img.gif();
  else                        img = img.jpeg({ quality, mozjpeg: true });

  const tmp = dst + '.tmp';
  await img.toFile(tmp);
  fs.renameSync(tmp, dst);
})().catch((e) => {
  console.error(String((e && e.message) || e));
  process.exit(1);
});
