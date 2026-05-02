// Build a 6-plane inside-out cube Group from Matterport-style skybox faces.
// Separate module because it's a self-contained piece of Three.js geometry
// with no dependency on the viewer's state.
//
// Matterport face convention (verified against reference viewers):
//   0 = front  (-Z)
//   1 = right  (+X)
//   2 = back   (+Z)
//   3 = left   (-X)
//   4 = up     (+Y)
//   5 = down   (-Y)
import * as THREE from 'three';

const SIZE = 1000;

// Matterport skybox face index convention (verified by visual inspection):
//   0 = +Y  top
//   5 = -Y  bottom
//   1,2,3,4 = sides (user to identify which is which)
//
// Rotation: each plane's FrontSide normal must point toward origin.
//   Plane default: lies in XY, normal = +Z.
//   rotateX(+PI/2) → normal -Y  (top at +Y)   ✓
//   rotateX(-PI/2) → normal +Y  (bottom at -Y) ✓
//   rotateY(-PI/2) → normal -X  (right at +X) ✓
//   rotateY(+PI/2) → normal +X  (left at -X)  ✓
//   rotateY(PI)    → normal -Z  (back at +Z)  ✓
//   no rotation    → normal +Z  (front at -Z) ✓
const FACE_DEFS = [
  { i: 0, pos: [0,  SIZE / 2, 0],  rot: [ Math.PI / 2, 0, 0], texRot: Math.PI / 2 }, // top    (+Y)
  { i: 1, pos: [ SIZE / 2, 0, 0],  rot: [0, -Math.PI / 2, 0] }, // right  (+X)
  { i: 2, pos: [0, 0,  SIZE / 2],  rot: [0,  Math.PI,     0] }, // back   (+Z)
  { i: 3, pos: [-SIZE / 2, 0, 0],  rot: [0,  Math.PI / 2, 0] }, // left   (-X)
  { i: 4, pos: [0, 0, -SIZE / 2],  rot: [0,  0,           0] }, // front  (-Z)
  { i: 5, pos: [0, -SIZE / 2, 0],  rot: [-Math.PI / 2, 0, 0], texRot: -Math.PI / 2 }, // bottom (-Y)
];

/**
 * Build an inside-out skybox Group from 6 face URLs/Files.
 *
 * @param {(string|File)[]} faces  Exactly 6 entries, Matterport order.
 * @param {object}          [opts]
 * @param {(pct:number)=>void} [opts.onProgress]  0..1 as faces resolve.
 * @param {Set<string>}     [opts.blobUrlRegistry]  Viewer can track blob URLs for revoke.
 * @param {boolean}         [opts.debug]
 * @returns {Promise<THREE.Group>}
 */
export function buildCubeMesh(faces, opts = {}) {
  const { onProgress, blobUrlRegistry, debug } = opts;

  // File → object URL (registry lets the caller revoke on scene swap)
  const urls = faces.map(f => {
    if (typeof f === 'string') return f;
    const u = URL.createObjectURL(f);
    blobUrlRegistry?.add(u);
    return u;
  });

  const group = new THREE.Group();
  group.name = 'skyboxCube';
  group.layers.set(0);

  if (debug) console.log('[cube-mesh] building', urls);

  const loader = new THREE.TextureLoader();
  loader.setCrossOrigin('anonymous');
  let loaded = 0;

  const promises = FACE_DEFS.map(({ i, pos, rot, texRot }) => new Promise((resolve, reject) => {
    if (!urls[i]) { reject(new Error(`missing face ${i}`)); return; }
    loader.load(
      urls[i],
      tex => {
        tex.colorSpace  = THREE.SRGBColorSpace;
        tex.anisotropy  = 4;
        tex.flipY       = true;
        if (texRot != null) {
          tex.rotation = texRot;
          tex.center.set(0.5, 0.5);
        }
        tex.needsUpdate = true;

        const geo = new THREE.PlaneGeometry(SIZE, SIZE);
        // Each plane is manually oriented so its front-face normal points at
        // the origin (unlike a BoxGeometry where normals point outward and
        // you'd use BackSide). From the camera at origin, the front side is
        // visible → FrontSide. BackSide would render nothing → black cube.
        const mat = new THREE.MeshBasicMaterial({
          map:        tex,
          side:       THREE.FrontSide,
          depthWrite: false,            // never occludes overlaid model
        });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.set(...pos);
        mesh.rotation.set(...rot);
        mesh.renderOrder = -10;
        mesh.layers.set(0);
        // Face index tag — viewer uses this for per-face LOD upgrades.
        mesh.userData.faceIndex = i;
        if (texRot != null) mesh.userData.texRot = texRot;
        group.add(mesh);

        // Debug: overlay face index number so user can identify which skybox is where
        if (debug) {
          const canvas = document.createElement('canvas');
          canvas.width = canvas.height = 256;
          const ctx = canvas.getContext('2d');
          // Transparent background — user can see the face image
          ctx.strokeStyle = '#fff';
          ctx.lineWidth = 8;
          ctx.font = 'bold 96px monospace';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          // Stroke for outline visibility on any background
          ctx.strokeText(String(i), 128, 128);
          ctx.fillStyle = '#fff';
          ctx.fillText(String(i), 128, 128);
          const labelTex = new THREE.CanvasTexture(canvas);
          labelTex.colorSpace = THREE.SRGBColorSpace;
          const labelMat = new THREE.MeshBasicMaterial({ map: labelTex, side: THREE.FrontSide, depthWrite: false, transparent: true });
          const labelMesh = new THREE.Mesh(geo, labelMat);
          labelMesh.position.copy(mesh.position);
          labelMesh.rotation.copy(mesh.rotation);
          labelMesh.position.multiplyScalar(1.01); // slightly forward to avoid z-fighting
          labelMesh.renderOrder = -9;
          labelMesh.layers.set(0);
          group.add(labelMesh);
        }

        loaded++;
        onProgress?.(loaded / 6);
        resolve();
      },
      undefined,
      err => {
        const u = err?.target?.src || urls[i];
        console.error('[cube-mesh] face', i, 'failed:', u, err);
        reject(Object.assign(new Error(`cube face ${i} failed: ${u}`), { target: err?.target }));
      },
    );
  }));

  return Promise.all(promises).then(() => group);
}
