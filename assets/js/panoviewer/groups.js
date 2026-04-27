// Panorama file grouping helpers.
// Separate module so both the viewer and page bootstrap (header.php) can
// share the same regex/detection logic without importing the full viewer.

// Matterport skybox face index → Three.js CubeTexture order
//   Three.js CubeTexture order: [+X right, -X left, +Y up, -Y down, +Z back, -Z front]
//   Matterport skybox N:        0=front, 1=right, 2=back, 3=left, 4=up, 5=down
//                               +X=1, -X=3, +Y=4, -Y=5, +Z=2, -Z=0
export const SKYBOX_FACE_ORDER = [1, 3, 4, 5, 2, 0];

export const SKYBOX_REGEX = /^(.+?)[_-]skybox[_-]?(\d)\.(jpe?g|png|webp)$/i;

/**
 * Group a list of files/URLs into cube-face panoramas and equirectangular images.
 *   items: File[] | string[]
 * Returns { cube: { [prefix]: orderedSix[] }, equirect: rest[], incomplete: {...} }
 */
export function detectPanoramaGroups(items) {
  const groups   = {};
  const equirect = [];

  for (const item of items) {
    const name = (item.name ?? item).split(/[\\/]/).pop();
    const m = name.match(SKYBOX_REGEX);
    if (m) {
      const [, prefix, idx] = m;
      (groups[prefix] ||= Array(6).fill(null))[parseInt(idx)] = item;
    } else if (/\.(jpe?g|png|webp)$/i.test(name)) {
      equirect.push(item);
    }
  }

  // Split complete (6 faces) vs incomplete groups
  const cube = {}, incomplete = {};
  for (const [prefix, faces] of Object.entries(groups)) {
    if (faces.every(Boolean)) cube[prefix] = faces;
    else incomplete[prefix] = faces;
  }
  return { cube, equirect, incomplete };
}
