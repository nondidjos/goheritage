// GoHéritage bridge — boots a PanoViewer from a host element's data
// attributes. Extracted from site/snippets/header.php so the same logic
// can run in any template / page that drops a `#pano-viewer` element with
// the standard data-* set (pano-urls, pano-scenes, pano-rewrite, model-url,
// model-texture, marker-offset-{x,y,z}, draco-path, goheritage-url, …).
//
// Usage:
//   import { boot } from '<plugin>/assets/goheritage-bridge.js';
//   boot(document.getElementById('pano-viewer'));

import { PanoViewer } from './panoviewer.js';

// Matterport cube-face filename: `{prefix}_skybox{0..5}.ext`. Must stay in
// sync with SKYBOX_REGEX in panoviewer.js.
const SKYBOX_RE = /^(.+?)[_-]skybox[_-]?(\d)\.(jpe?g|png|webp)$/i;
const stemOf = (s) => (String(s || '').split(/[\\/]/).pop() || '').replace(/\.[^/.]+$/, '').toLowerCase();
const norm   = (v) => String(v || '').toLowerCase().replace(/-/g, '');

/**
 * Group a list of {url, filename} (or plain URL strings) into cube scenes
 * keyed by skybox prefix; ungrouped items survive as equirect scenes.
 *
 * @param {Array<string|{url:string, filename?:string, preview?:string}>} items
 * @param {Object<string, {preview:string}>} rewrite  panoRewrite map (stem → {preview})
 */
export function groupSkyboxes(items, rewrite = {}) {
  const previewOf = (filename, fullUrl) => rewrite[stemOf(filename)]?.preview || fullUrl;
  const groups    = {};
  const groupsLow = {};
  const equirect  = [];
  items.forEach(it => {
    const url      = it.url || it;
    const filename = it.filename || (url.split(/[\\/]/).pop() || '');
    const m = filename.match(SKYBOX_RE);
    if (m) {
      const prefix = m[1];
      const idx    = parseInt(m[2], 10);
      (groups[prefix]    = groups[prefix]    || [])[idx] = url;
      (groupsLow[prefix] = groupsLow[prefix] || [])[idx] = previewOf(filename, url);
    } else {
      equirect.push({ url, filename, preview: it.preview || url });
    }
  });
  const cubeScenes = [];
  for (const [prefix, faces] of Object.entries(groups)) {
    if (faces.filter(Boolean).length !== 6) {
      console.warn('[pano] incomplete cube group, skipped:', prefix, faces);
      continue;
    }
    cubeScenes.push({
      id:       prefix,
      title:    prefix.slice(0, 8),
      type:     'cube',
      faces,
      facesLow: groupsLow[prefix] || faces,
      preview:  (groupsLow[prefix] || faces)[0],
      hotspots: [],
    });
  }
  const equirectScenes = equirect.map((e, i) => {
    const name = (e.filename || 'scene').replace(/\.[^/.]+$/, '');
    return {
      id:      name || ('scene-' + i),
      title:   name,
      image:   e.url,
      preview: e.preview,
      hotspots: [],
    };
  });
  return { cubeScenes, equirectScenes };
}

/**
 * Auto-generate nav hotspots between scenes with 3D positions. Emits world-
 * space `offset` vectors; the viewer rotates the cube/sphere by each scene's
 * bake quaternion so world dirs map directly to viewer dirs.
 */
export function linkByPosition(list, maxDist = 8) {
  for (let i = 0; i < list.length; i++) {
    const A = list[i]; if (!A.position) continue;
    for (let j = 0; j < list.length; j++) {
      if (i === j) continue;
      const B = list[j]; if (!B.position) continue;
      const dx = B.position.x - A.position.x;
      const dy = B.position.y - A.position.y;
      const dz = B.position.z - A.position.z;
      const dist = Math.sqrt(dx * dx + dy * dy + dz * dz);
      if (dist > maxDist || dist < 0.01) continue;
      A.hotspots.push({
        id: `${A.id}__to__${B.id}`,
        type: 'nav', target: B.id, label: B.title || B.id,
        offset: { x: dx, y: dy, z: dz },
      });
    }
  }
}

/**
 * Enrich cube/equirect scenes with metadata from a GoHéritage JSON file
 * (positions, titles, initialView, pano_quat). Matches by id, hyphen-
 * normalised id, or skybox-prefix / panorama-stem fallback. Falls back to
 * index pairing when zero IDs match (Matterport dumps in sweep order).
 */
export function enrichFromJson(list, jsonData) {
  if (!jsonData) return;
  const byId     = Object.fromEntries(list.map(s => [s.id.toLowerCase(), s]));
  const byIdNorm = Object.fromEntries(list.map(s => [norm(s.id), s]));
  let matched = 0;
  console.log('[pano] sampleSceneIds:', JSON.stringify(list.slice(0, 3).map(s => s.id)));
  ['exterior', 'interior'].forEach(side => {
    const bucket = jsonData[side];
    if (!bucket || !Array.isArray(bucket.hotspots)) return;
    bucket.hotspots.forEach(h => {
      const hid = String(h.id || '').toLowerCase();
      let sc = byId[hid] || byIdNorm[norm(h.id)];
      if (!sc && h.panorama) {
        const stem = stemOf(h.panorama);
        const m = stem.match(/^(.+?)[_-]skybox[_-]?\d$/i);
        const key = (m ? m[1] : stem).toLowerCase();
        const keyN = norm(key);
        sc = byId[key] || byIdNorm[keyN]
          || Object.values(byId).find(s => s.id.toLowerCase().startsWith(key))
          || Object.values(byIdNorm).find(s => norm(s.id).startsWith(keyN));
      }
      if (!sc) return;
      matched++;
      if (h.position)  sc.position  = h.position;
      if (h.title)     sc.title     = h.title;
      if (h.pano_quat) sc.pano_quat = h.pano_quat;
      if (h.pano_yaw != null || h.pano_pitch != null) {
        sc.initialView = { yaw: h.pano_yaw ?? 0, pitch: h.pano_pitch ?? 0 };
      }
    });
  });
  console.log(`[pano] enriched ${matched} / ${list.length} scenes from JSON`);

  if (matched === 0) {
    const allHs = [];
    ['exterior', 'interior'].forEach(side => {
      const b = jsonData[side];
      if (b?.hotspots) allHs.push(...b.hotspots);
    });
    if (allHs.length) {
      const sorted = [...list].sort((a, b) => a.id.localeCompare(b.id));
      const pairs = Math.min(sorted.length, allHs.length);
      for (let i = 0; i < pairs; i++) {
        const sc = sorted[i], h = allHs[i];
        if (h.position) {
          sc.position = { ...h.position, _approx: true };
          if (h.title && !sc._titleSet) sc.title = h.title;
        }
      }
      console.warn(`[pano] zero ID matches — fell back to index pairing for ${pairs} scenes (positions approximate, verify visually)`);
    }
  }
}

/**
 * Read marker_offset_x/y/z data attributes into a {x,y,z} object. Each axis
 * is independent — empty fields are omitted so the viewer auto-centers on
 * those axes.
 */
function readMarkerOffset(el) {
  const out = {};
  ['x', 'y', 'z'].forEach(ax => {
    const v = el.dataset['markerOffset' + ax.toUpperCase()];
    if (v !== undefined && v !== '') out[ax] = parseFloat(v);
  });
  return Object.keys(out).length ? out : undefined;
}

/**
 * Boot a PanoViewer from a host element's data attributes. See header.php
 * for the canonical attribute list. Returns the viewer instance (also
 * mounted on `window.viewer` for ad-hoc console access).
 */
export function boot(el, opts = {}) {
  if (!el) return null;
  const viewer = new PanoViewer(el, {
    autoRotate: false,
    urlHashSync: false,
    preloadNeighbors: true,
    dracoPath: el.dataset.dracoPath || undefined,
    markerOffset: readMarkerOffset(el),
    ...opts,
  });
  if (opts.exposeGlobal !== false) window.viewer = viewer;

  // Model load deferred — it was the biggest contributor to the initial 3 s
  // freeze (parse + merge + texture upload all sync on main thread). We
  // wait until the page is idle (~3 s after pano boots), then load in the
  // background. Dollhouse button reveals only after model is ready.
  const modelUrl = el.dataset.modelUrl || null;
  const modelTex = el.dataset.modelTexture || null;
  if (modelUrl) {
    const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 3000));
    idle(() => {
      viewer.loadModel(modelUrl, modelTex ? { texture: modelTex } : {})
        .catch(err => console.warn('pano: model load failed:', err));
    }, { timeout: 8000 });
  }

  const urls    = JSON.parse(el.dataset.panoUrls    || '[]');
  const scenes  = JSON.parse(el.dataset.panoScenes  || '[]');
  const rewrite = JSON.parse(el.dataset.panoRewrite || '{}');
  const ghUrl   = el.dataset.goheritageUrl || null;

  const source = scenes.length
    ? scenes
    : urls.map(u => ({ url: u, filename: (u.split(/[\\/]/).pop() || '') }));

  const { cubeScenes, equirectScenes } = groupSkyboxes(source, rewrite);
  const allScenes = [...cubeScenes, ...equirectScenes];

  const bootScenes = (list) => {
    if (!list.length) { console.warn('[pano] bootScenes: empty list'); return; }
    linkByPosition(list);
    list.forEach(s => viewer.addScene(s));
    viewer.loadScene(list[0].id);
  };

  if (ghUrl) {
    fetch(ghUrl)
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(data => { enrichFromJson(allScenes, data); bootScenes(allScenes); })
      .catch(err => { console.warn('goheritage JSON:', err); bootScenes(allScenes); });
  } else {
    bootScenes(allScenes);
  }

  return viewer;
}
