/**
 * GoHéritage — minimal point-cloud viewer
 *
 * Renders a single uploaded PLY/PCD as one THREE.Points cloud (the common case
 * shown straight in the panel). Big octree-streamed clouds use the COPC viewer
 * (copc-viewer.js) or an external Potree URL instead.
 *
 * Mounts onto #gh-pointcloud-viewer and reads:
 *   data-src    — URL of the point-cloud file (.ply or .pcd)
 *   data-format — the file extension ("ply" | "pcd")
 *
 * Shared renderer/loop/chrome/teardown live in pointcloud-common.js; this file
 * owns only the PLY/PCD loading and the whole-file colour + camera framing.
 */

import * as THREE from 'three';
import { PLYLoader } from 'three/addons/loaders/PLYLoader.js';
import { PCDLoader } from 'three/addons/loaders/PCDLoader.js';
import {
  Z_UP_FIX, makeRound,
  createStage, createRenderLoop, attachResize,
  makeProgress, makeSizeControls, makeControlsHint, disposeStage,
} from './pointcloud-common.js';

// Vertex-colour boost. Scan colours read dim on the dark stage (they carry
// indoor exposure + sRGB-as-linear loss), so lift them a touch. >1 components
// are fine: the shader multiplies before output encoding.
const COLOR_BOOST = 1.35;

function initPointCloud(container) {
  const src = container.dataset.src;
  const format = (container.dataset.format || 'ply').toLowerCase();
  if (!src) return;

  const stage = createStage(container);
  const { scene, camera, controls } = stage;
  const { requestRender } = createRenderLoop(stage);
  attachResize(stage, requestRender);

  const progress = makeProgress(container);
  let pointsMaterial = null;
  makeSizeControls(stage, () => pointsMaterial, requestRender);
  makeControlsHint(stage);

  // ── Add a cloud, centre it, fix the up-axis, and frame the camera ─────
  function addCloud(geometry, existingPoints) {
    geometry.computeBoundingBox();
    const size = new THREE.Vector3();
    const center = new THREE.Vector3();
    geometry.boundingBox.getSize(size);
    geometry.boundingBox.getCenter(center);
    const diag = size.length() || 1;
    const hasColor = !!geometry.getAttribute('color');

    let points;
    if (existingPoints) {
      // PCDLoader already returns a THREE.Points; just tune its material.
      points = existingPoints;
      points.material.size = diag / 800;
      points.material.sizeAttenuation = true;
      if (hasColor) points.material.vertexColors = true;
      points.material.needsUpdate = true;
    } else {
      const material = new THREE.PointsMaterial({
        size: diag / 800,
        sizeAttenuation: true,
        vertexColors: hasColor,
        color: hasColor ? 0xffffff : 0x9fb4c7,
      });
      points = new THREE.Points(geometry, material);
    }
    pointsMaterial = points.material;

    // Round points instead of GL's default squares.
    makeRound(pointsMaterial);

    // Lift scan colours — they read dim on the dark stage otherwise.
    if (hasColor) pointsMaterial.color.setScalar(COLOR_BOOST);

    // Recentre by baking the offset into the VERTICES, not the object's
    // position. Scan exports carry large absolute coordinates (survey CRS,
    // or a metre-scale local frame offset from origin). If we only moved the
    // object (points.position.sub(center)) the vertex attribute would still
    // hold those large values, and `modelViewMatrix * position` in float32
    // suffers catastrophic cancellation — the cloud visibly vibrates as the
    // camera rotates. Translating the geometry makes the stored coordinates
    // small (centred on 0), which keeps the matrix math precise.
    geometry.translate(-center.x, -center.y, -center.z);
    geometry.computeBoundingSphere();

    const wrap = new THREE.Group();
    wrap.rotation.x = Z_UP_FIX;
    wrap.add(points);
    scene.add(wrap);

    // ── Frame the camera on the OUTSIDE of the cloud ──────────────────────
    // A fixed camera octant lands on the wrong side of one-sided scans
    // (building facades especially) about half the time. When the cloud has
    // per-point normals — most facade / photogrammetry exports do — average
    // them to find which way the surface faces, and sit the camera on that
    // side, so we open looking AT the facade instead of through its back.
    const radius = diag / 2;
    const dist = radius * 1.7;       // pulled back enough to frame the whole cloud
    const elevation = radius * 0.4;  // a gentle look-down

    // Outward facing direction, in GEOMETRY space (scan data is Z-up).
    let face = null;
    const nAttr = geometry.getAttribute('normal');
    if (nAttr && nAttr.count) {
      const acc = new THREE.Vector3();
      const tmp = new THREE.Vector3();
      // Sample (≤20k points) — averaging every normal on a multi-million-point
      // cloud is needless work; the dominant direction is stable at this size.
      const step = Math.max(1, Math.floor(nAttr.count / 20000));
      for (let i = 0; i < nAttr.count; i += step) {
        tmp.set(nAttr.getX(i), nAttr.getY(i), nAttr.getZ(i));
        if (tmp.lengthSq() > 0) acc.add(tmp.normalize());
      }
      acc.z = 0;                       // drop the up component → horizontal facing
      if (acc.lengthSq() > 1e-4) face = acc.normalize();
    }
    if (!face) {
      // No usable normals: look along the thinnest horizontal axis (a facade's
      // depth is its smallest horizontal extent). Sign is arbitrary here.
      face = (size.x <= size.y)
        ? new THREE.Vector3(1, 0, 0)
        : new THREE.Vector3(0, 1, 0);
    }

    // Eye position in geometry space: out along the facing direction, lifted a
    // little, with a slight lateral skew for a flattering 3/4 angle. The cloud
    // is centred on the origin (we translated the geometry), so this is
    // relative to the model centre.
    const sideDir = new THREE.Vector3(-face.y, face.x, 0); // horizontal perpendicular
    const eyeGeom = new THREE.Vector3()
      .addScaledVector(face, dist)
      .addScaledVector(sideDir, dist * 0.3);
    eyeGeom.z += elevation;

    // The cloud lives inside `wrap` (the Z-up→Y-up rotation), so convert the
    // geometry-space eye into world space through it. wrap is rotation-only, so
    // the model centre still maps to the world origin.
    wrap.updateMatrixWorld(true);
    camera.position.copy(wrap.localToWorld(eyeGeom));

    // Keep the near/far span tight around the model so the depth buffer has
    // adequate precision (a huge near:far ratio also causes flicker).
    camera.near = Math.max(radius / 500, 0.01);
    camera.far = radius * 20;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.update();

    // Depth cue: fade the far tail of the cloud into the background so depth
    // reads at a glance. Near points already render larger (sizeAttenuation,
    // above); the fog supplies the complementary "further = fainter" half.
    scene.fog = new THREE.Fog(0x1a1a1a, dist, dist + radius * 4);

    progress.hide();
    requestRender();
  }

  // ── Load ──────────────────────────────────────────────────────────────
  if (format === 'pcd') {
    new PCDLoader().load(src, (pts) => addCloud(pts.geometry, pts), progress.onProgress, progress.fail);
  } else {
    new PLYLoader().load(src, (geo) => addCloud(geo, null), progress.onProgress, progress.fail);
  }

  window.addEventListener('pagehide', () => disposeStage(stage), { once: true });
}

const el = document.getElementById('gh-pointcloud-viewer');
if (el) initPointCloud(el);
