<?php
// global header — included in all templates
$isMapPage     = $page->template()->name() === 'map';
$isProjectPage = $page->template()->name() === 'project';
$cssFiles      = ['assets/css/app.css', 'assets/css/custom.css'];
if ($isMapPage) {
    $cssFiles[] = 'assets/css/map.css';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= $page->title()->html() ?> — <?= $site->title()->html() ?></title>
  <meta name="description" content="<?= $page->description()->or($site->description())->html() ?>">
  <?= css($cssFiles) ?>
  <?php if ($isMapPage): ?>
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.css">
  <?php endif ?>
  <?php
  // Only load a 3D/pano viewer when no external viewer URL is set.
  // Decide which of the two bundled viewers is needed on the project page.
  $viewerMode = null;
  if ($isProjectPage && $page->viewer_url()->isEmpty()) {
      $panoFilesCount = $page->pano_files()->toFiles()->count();
      if ($panoFilesCount === 0) {
          $panoFilesCount = $page->images()->template('panorama')->count();
      }
      $hasPanoJson    = ($page->file('pano-hotspots.json') ?? $page->pano_hotspots_json()->toFile()) !== null;
      $hasPanoAssets  = ($panoFilesCount > 0 || $hasPanoJson);
      $hasModelAssets = $page->file('exterior.obj') || $page->file('exterior.glb')
                     || $page->file('interior.obj') || $page->file('interior.glb')
                     || $page->model_obj()->toFile() || $page->model_obj_interior()->toFile();
      $pref = $page->viewer_preference()->or('auto')->value();
      if ($pref === 'model')         $viewerMode = $hasModelAssets ? 'model' : ($hasPanoAssets ? 'pano' : null);
      elseif ($pref === 'panorama')  $viewerMode = $hasPanoAssets  ? 'pano'  : ($hasModelAssets ? 'model' : null);
      else                           $viewerMode = $hasPanoAssets  ? 'pano'  : ($hasModelAssets ? 'model' : null);
  }
  if ($isProjectPage): ?>
  <?= css('assets/css/lightbox.css') ?>
  <?= css('assets/css/viewer.css') ?>
  <?php endif ?>
  <?php if ($viewerMode !== null): ?>
  <script type="importmap">
  {
    "imports": {
      "three": "<?= url('node_modules/three/build/three.module.min.js') ?>",
      "three/addons/": "<?= url('node_modules/three/examples/jsm/') ?>"
    }
  }
  </script>
  <?php endif ?>
  <?php if ($viewerMode === 'model'): ?>
  <script type="module" src="<?= url('assets/js/viewer.js') ?>"></script>
  <?php elseif ($viewerMode === 'pano'): ?>
  <?= css('assets/css/panoviewer.css') ?>
  <script type="module">
    import { PanoViewer, detectPanoramaGroups } from '<?= url('assets/js/panoviewer.js') ?>';
    const el = document.getElementById('pano-viewer');
    if (el) {
      // Manual marker offset: read the three numeric fields from the page
      // blueprint. Each axis is independently optional — if a field is empty,
      // the viewer falls back to auto-centering for that axis.
      const _moX = el.dataset.markerOffsetX;
      const _moY = el.dataset.markerOffsetY;
      const _moZ = el.dataset.markerOffsetZ;
      const markerOffset = {};
      if (_moX !== undefined && _moX !== '') markerOffset.x = parseFloat(_moX);
      if (_moY !== undefined && _moY !== '') markerOffset.y = parseFloat(_moY);
      if (_moZ !== undefined && _moZ !== '') markerOffset.z = parseFloat(_moZ);

      const viewer = new PanoViewer(el, {
        autoRotate: false,
        urlHashSync: false,
        preloadNeighbors: true,
        dracoPath: el.dataset.dracoPath || undefined,
        markerOffset: Object.keys(markerOffset).length ? markerOffset : undefined,
      });
      window.viewer = viewer;

      // 1) Load the 3D model FIRST so the dollhouse button appears and
      //    scene markers render over the mesh (Matterport-style teleport).
      const modelUrl = el.dataset.modelUrl || null;
      const modelTex = el.dataset.modelTexture || null;
      if (modelUrl) {
        viewer.loadModel(modelUrl, modelTex ? { texture: modelTex } : {})
          .catch(err => console.warn('pano: model load failed:', err));
      }

      // 2) Load scenes. The GoHéritage JSON is preferred because it carries
      //    hotspot.position (required for dollhouse markers) and hotspot.panorama
      //    (scene images). Without it we fall back to a flat pano list, which
      //    works but gives no dollhouse markers.
      //
      // All scene image URLs come from the server as pre-resized Kirby thumbs
      // (4096-wide JPEG + 1024-wide preview) — originals are typically 30-50MB
      // equirects which would stall the panel & browser cache. The `rewrite`
      // map lets us intercept raw filenames referenced inside the goheritage
      // JSON and swap them for thumb URLs.
      const urls    = JSON.parse(el.dataset.panoUrls    || '[]');
      const scenes  = JSON.parse(el.dataset.panoScenes  || '[]');
      const rewrite = JSON.parse(el.dataset.panoRewrite || '{}');
      const ghUrl   = el.dataset.goheritageUrl || null;

      // Regex matches Matterport cube-face filenames: `{prefix}_skybox{0..5}.ext`.
      // Must stay in sync with SKYBOX_REGEX in panoviewer.js.
      const SKYBOX_RE = /^(.+?)[_-]skybox[_-]?(\d)\.(jpe?g|png|webp)$/i;
      const stemOf = (s) => (String(s || '').split(/[\\/]/).pop() || '').replace(/\.[^/.]+$/, '').toLowerCase();

      // Resolve the low-res preview URL for a face filename via the rewrite
      // table generated by the server (panoRewrite[stem].preview points at
      // a Kirby thumb regardless of whether the original kept its name).
      const previewOf = (filename, fullUrl) => {
        const stem = stemOf(filename);
        return rewrite[stem]?.preview || fullUrl;
      };

      // Group a list of URLs (or {url, filename} objects) into cube scenes
      // keyed by skybox prefix. Ungrouped survive as equirect scenes.
      // Each cube scene carries `faces` (full-res, used after LOD upgrade)
      // AND `facesLow` (1024-wide thumbs) — the viewer renders the low set
      // immediately for instant scene swaps and upgrades to high in the bg.
      const groupSkyboxes = (items) => {
        const groups    = {}; // prefix -> faces[6]
        const groupsLow = {}; // prefix -> facesLow[6]
        const equirect = [];
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
          // Need all 6 faces to stitch
          if (faces.filter(Boolean).length !== 6) {
            console.warn('[pano] incomplete cube group, skipped:', prefix, faces);
            continue;
          }
          cubeScenes.push({
            id:        prefix,
            title:     prefix.slice(0, 8),
            type:      'cube',
            faces,
            facesLow:  groupsLow[prefix] || faces,
            preview:   (groupsLow[prefix] || faces)[0],
            hotspots:  [],
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
      };

      // Auto-generate nav hotspots between scenes with 3D positions.
      // Emits `offset = {x, y, z}` in WORLD space (Three.js Y-up). The viewer
      // rotates the cube/sphere of the active scene by its bake quaternion so
      // world directions map directly to viewer directions — arrows just sit
      // at the raw world offset, no rotation needed here.
      const linkByPosition = (list, maxDist = 8) => {
        for (let i = 0; i < list.length; i++) {
          const A = list[i]; if (!A.position) continue;
          for (let j = 0; j < list.length; j++) {
            if (i === j) continue;
            const B = list[j]; if (!B.position) continue;
            const dx = B.position.x - A.position.x;
            const dy = B.position.y - A.position.y;
            const dz = B.position.z - A.position.z;
            const dist = Math.sqrt(dx*dx + dy*dy + dz*dz);
            if (dist > maxDist || dist < 0.01) continue;
            A.hotspots.push({
              id: `${A.id}__to__${B.id}`,
              type: 'nav', target: B.id, label: B.title || B.id,
              offset: { x: dx, y: dy, z: dz },
            });
          }
        }
      };

      // Enrich cube/equirect scenes with JSON metadata (position, title,
      // initialView, hotspots). Match by: exact id, skybox prefix, or
      // panorama-filename stem.
      const enrichFromJson = (list, jsonData) => {
        if (!jsonData) return;
        // Build id index TWO ways: as-is and with hyphens stripped — Matterport
        // sweep UUIDs sometimes get reformatted between dump tool and CMS upload
        // (e.g. `abc-def-123` ⇄ `abcdef123`), so we normalize on both sides.
        const norm = (v) => String(v || '').toLowerCase().replace(/-/g, '');
        const byId     = Object.fromEntries(list.map(s => [s.id.toLowerCase(), s]));
        const byIdNorm = Object.fromEntries(list.map(s => [norm(s.id), s]));
        let matched = 0;
        // One-shot diagnostic — stringified so collapsed objects in DevTools
        // don't hide the values we care about.
        console.log('[pano] sampleSceneIds:', JSON.stringify(list.slice(0, 3).map(s => s.id)));
        console.log('[pano] sampleSceneIdsNorm:', JSON.stringify(list.slice(0, 3).map(s => norm(s.id))));
        ['exterior', 'interior'].forEach(side => {
          const bucket = jsonData[side];
          if (!bucket || !Array.isArray(bucket.hotspots)) return;
          console.log(`[pano] ${side} hotspots sample:`, JSON.stringify(bucket.hotspots.slice(0, 3).map(h => ({
            id: h.id, idNorm: norm(h.id), panorama: h.panorama, position: h.position,
          })), null, 2));
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
            if (h.position)                 sc.position = h.position;
            if (h.title)                    sc.title    = h.title;
            if (h.pano_quat)                sc.pano_quat = h.pano_quat;
            if (h.pano_yaw != null || h.pano_pitch != null) {
              sc.initialView = { yaw: h.pano_yaw ?? 0, pitch: h.pano_pitch ?? 0 };
            }
          });
        });
        console.log(`[pano] enriched ${matched} / ${list.length} scenes from JSON`);

        // Fallback: zero ID matches → pair by index. Matterport dumps are
        // typically in sweep order, and scenes (sorted by filename) often
        // line up. Marked .position._approx so the dollhouse diagnostics can
        // warn if positions look wrong.
        if (matched === 0) {
          const allHs = [];
          ['exterior', 'interior'].forEach(side => {
            const b = jsonData[side];
            if (b?.hotspots) allHs.push(...b.hotspots);
          });
          if (allHs.length) {
            // Sort scenes by id for a stable pairing — file uploads usually
            // arrive in lexical (UUID) order which matches sweep order in many
            // Matterport dumps.
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
      };

      const bootScenes = (list) => {
        if (!list.length) { console.warn('[pano] bootScenes: empty list'); return; }
        linkByPosition(list);
        console.log('[pano] boot', list.length, 'scenes:', list.map(s => ({
          id: s.id, type: s.type || 'equirect', faces: s.faces?.length, hotspots: s.hotspots?.length, position: s.position,
        })));
        list.forEach(s => viewer.addScene(s));
        viewer.loadScene(list[0].id);
      };

      // Prefer server-provided scenes list (has thumb URLs + filenames).
      const source = scenes.length
        ? scenes
        : urls.map(u => ({ url: u, filename: (u.split(/[\\/]/).pop() || '') }));

      const { cubeScenes, equirectScenes } = groupSkyboxes(source);
      const allScenes = [...cubeScenes, ...equirectScenes];

      if (ghUrl) {
        fetch(ghUrl)
          .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
          .then(data => {
            enrichFromJson(allScenes, data);
            bootScenes(allScenes);
          })
          .catch(err => {
            console.warn('goheritage JSON:', err);
            bootScenes(allScenes);
          });
      } else {
        bootScenes(allScenes);
      }
    }
  </script>
  <?php endif ?>
  <link rel="shortcut icon" type="image/x-icon" href="<?= url('favicon.ico') ?>">
</head>
<body>

<header class="sticky top-0 z-50 bg-white">
  <div class="grid-7 items-center py-4">

    <!-- logo -->
    <div class="col-2">
      <a class="no-underline hover:no-underline" href="<?= $site->url() ?>" aria-label="<?= $site->title()->html() ?>">
        <img src="<?= url('assets/logos/goheritage.svg') ?>" alt="GoHéritage" class="h-7 w-auto rounded-none">
      </a>
    </div>

    <!-- spacer (hidden on mobile, header becomes flex) -->
    <div class="col-3 hidden md:block"></div>

    <!-- mobile hamburger -->
    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Menu" aria-expanded="false">
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
    </button>

    <!-- navigation — right-aligned, 2 cols spaced out -->
    <nav class="site-nav col-2 flex w-full items-center justify-between" id="site-nav" aria-label="Navigation principale">
      <?php foreach ($site->children()->listed()->not($site->homePage()) as $item): ?>
      <a
        class="font-sans text-sm uppercase tracking-wider text-ink no-underline transition-colors duration-150 hover:underline hover:text-ink"
        href="<?= $item->url() ?>"
        <?php e($item->isOpen(), 'aria-current="page"') ?>
      ><?= $item->title()->html() ?></a>
      <?php endforeach ?>

      <!-- mobile close button -->
      <button class="mobile-menu-close" id="mobile-menu-close" aria-label="Fermer le menu">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
        <span>Fermer</span>
      </button>
    </nav>

  </div>
</header>

<?php
$tplName = $page->template()->name();
if ($tplName === 'project'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->parent()->title()->value(), 'url' => $page->parent()->url()],
  ['label' => $page->title()->value()],
]]) ?>
<?php elseif ($tplName === 'article'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->parent()->title()->value(), 'url' => $page->parent()->url()],
  ['label' => $page->title()->value()],
]]) ?>
<?php elseif ($tplName === 'blog'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->title()->value()],
]]) ?>
<?php elseif ($tplName === 'map'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->title()->value()],
]]) ?>
<?php endif ?>

<main<?= $isMapPage ? ' id="map-main"' : '' ?>>
