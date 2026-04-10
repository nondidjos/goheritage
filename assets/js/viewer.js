/**
 * GoHéritage — Three.js Viewer with annotation hotspot system
 *
 * Features:
 *   - GLB (DRACO) / OBJ+MTL model loading
 *   - CSS2DRenderer labels anchored to 3D hotspot positions
 *   - Smooth camera fly-to animation
 *   - Blender Empty objects with userData are read as hotspots
 *   - CMS annotation data (JSON) merged with hotspot positions
 *   - Adaptive pixel-ratio for performance on weak GPUs
 *   - Left-panel ↔ 3D label two-way interaction
 */

import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { MTLLoader } from 'three/addons/loaders/MTLLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { CSS2DRenderer, CSS2DObject } from 'three/addons/renderers/CSS2DRenderer.js';

// ── Main init ────────────────────────────────────────────────────────────────

function initViewer(container) {
  const objUrl = container.dataset.obj || null;
  const glbUrl = container.dataset.glb || null;
  const texUrl = container.dataset.texture || null;
  const normUrl = container.dataset.normal || null;
  const dracoPath = container.dataset.dracoPath || null;
  const interiorObjUrl = container.dataset.objInterior || null;
  const interiorGlbUrl = container.dataset.glbInterior || null;
  const interiorTexUrl = container.dataset.textureInterior || null;
  const interiorNormUrl = container.dataset.normalInterior || null;
  const hotspotsJsonUrl = container.dataset.hotspotsJson || null;

  // CMS annotations passed as JSON data attribute
  let cmsAnnotations = [];
  try { cmsAnnotations = JSON.parse(container.dataset.annotations || '[]'); } catch (_) { }

  // Hotspot positions loaded from JSON file (set after fetch)
  var jsonHotspots = null; // { exterior: { hotspots: [...] }, interior: { hotspots: [...] } }

  var interiorGlbDerivedUrl = interiorGlbUrl || (interiorObjUrl ? interiorObjUrl.replace(/\.obj$/i, '.glb') : null);
  if (!objUrl && !glbUrl && !interiorGlbDerivedUrl && !interiorObjUrl) return;

  // ── Mobile detection ─────────────────────────────────────────────────────
  var isMobile = window.innerWidth <= 768 || ('ontouchstart' in window);

  // ── Renderer ─────────────────────────────────────────────────────────────
  const renderer = new THREE.WebGLRenderer({
    antialias: !isMobile,
    alpha: false,
    powerPreference: isMobile ? 'low-power' : 'default',
  });
  renderer.setPixelRatio(isMobile ? 1 : Math.min(window.devicePixelRatio, 2));
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  container.appendChild(renderer.domElement);

  // ── CSS2D Renderer (label overlay) ───────────────────────────────────────
  const labelRenderer = new CSS2DRenderer();
  labelRenderer.setSize(container.clientWidth, container.clientHeight);
  labelRenderer.domElement.id = 'viewer-labels';
  container.appendChild(labelRenderer.domElement);

  // ── Scene ────────────────────────────────────────────────────────────────
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1a1a1a);
  scene.add(new THREE.AmbientLight(0xffffff, 0.6));

  const dirLight = new THREE.DirectionalLight(0xffffff, 0.8);
  dirLight.position.set(5, 10, 7.5);
  scene.add(dirLight);

  // ── Camera ───────────────────────────────────────────────────────────────
  const camera = new THREE.PerspectiveCamera(
    50, container.clientWidth / container.clientHeight, 0.1, 10000
  );

  // ── Controls ─────────────────────────────────────────────────────────────
  // IMPORTANT: attach to the WebGL canvas, not the CSS2D overlay
  // the overlay has pointer-events:none so it passes events to the canvas
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.screenSpacePanning = true;
  controls.maxDistance = 5000;
  controls.zoomSpeed = 1.2;

  // Forward wheel events from labels so zooming still works without scrolling the page
  container.addEventListener('wheel', function (e) {
    if (e.target.closest('.viewer-label')) {
      e.preventDefault();
      var clone = new WheelEvent('wheel', {
        clientX: e.clientX,
        clientY: e.clientY,
        deltaX: e.deltaX,
        deltaY: e.deltaY,
        deltaZ: e.deltaZ,
        deltaMode: e.deltaMode,
        bubbles: true,
        cancelable: true
      });
      renderer.domElement.dispatchEvent(clone);
    }
  }, { passive: false });

  // ── Progress overlay ─────────────────────────────────────────────────────
  const progress = document.createElement('div');
  progress.className = 'viewer-progress';
  progress.innerHTML =
    '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
    '<span class="viewer-progress-text">chargement du modèle\u2026</span>';
  container.appendChild(progress);

  const progressFill = progress.querySelector('.viewer-progress-fill');
  const progressText = progress.querySelector('.viewer-progress-text');

  function updateProgress(loaded, total) {
    if (total > 0) {
      const pct = Math.round((loaded / total) * 100);
      progressFill.style.width = pct + '%';
      progressText.textContent = 'chargement\u2026 ' + pct + '%';
    }
  }

  function hideProgress() {
    progress.style.opacity = '0';
    setTimeout(function () { progress.remove(); }, 400);
  }

  // ── State ────────────────────────────────────────────────────────────────
  var hotspots = [];       // active side's hotspot list
  var activeHotspotId = null;
  var modelCenter = new THREE.Vector3();
  var modelRadius = 1;
  var flyAnimation = null; // active fly-to RAF id

  // ── Dual-model state ─────────────────────────────────────────────────────
  var currentSide = 'exterior';
  var modelObjects = { exterior: null, interior: null };
  var hotspotSets = { exterior: [], interior: [] };

  // ── Fade overlay (used when switching models) ────────────────────────────
  var fadeEl = document.createElement('div');
  fadeEl.className = 'viewer-fade';
  container.appendChild(fadeEl);

  // ── Controls hint (desktop only) ─────────────────────────────────────────
  if (!isMobile) {
    var hint = document.createElement('div');
    hint.className = 'viewer-controls-hint';
    hint.innerHTML =
      '<span class="viewer-controls-hint__label">Rotation</span>' +
      '<svg width="22" height="32" viewBox="0 0 22 32" fill="none" xmlns="http://www.w3.org/2000/svg">' +
      '<rect x="1" y="1" width="20" height="30" rx="10" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>' +
      '<path d="M1 12 L1 9 Q1 2 11 2 L11 15 L1 15 Z" fill="rgba(255,255,255,0.15)"/>' +
      '<path d="M21 12 L21 9 Q21 2 11 2 L11 15 L21 15 Z" fill="rgba(255,255,255,0.15)"/>' +
      '<line x1="11" y1="2" x2="11" y2="15" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>' +
      '<rect x="9.5" y="5" width="3" height="6" rx="1.5" fill="rgba(255,255,255,0.5)"/>' +
      '</svg>' +
      '<span class="viewer-controls-hint__label">Déplacer</span>';
    container.appendChild(hint);

    var hintTimer = null;
    var pointerDown = false;
    function scheduleHint() {
      clearTimeout(hintTimer);
      if (!pointerDown) {
        hintTimer = setTimeout(function () { hint.classList.add('is-visible'); }, 1000);
      }
    }
    function onPointerDown() {
      pointerDown = true;
      clearTimeout(hintTimer);
      hint.classList.remove('is-visible');
    }
    function onPointerUp() {
      pointerDown = false;
      scheduleHint();
    }
    scheduleHint();
    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointerup', onPointerUp);
    renderer.domElement.addEventListener('pointercancel', onPointerUp);
    renderer.domElement.addEventListener('wheel', function () {
      clearTimeout(hintTimer);
      hint.classList.remove('is-visible');
      scheduleHint();
    }, { passive: true });
  }

  // ── Frame camera to fit model ────────────────────────────────────────────
  function frameModel(object) {
    var box = new THREE.Box3().setFromObject(object);
    var size = box.getSize(new THREE.Vector3());
    var center = box.getCenter(new THREE.Vector3());

    modelCenter.copy(center);
    modelRadius = size.length() / 2;

    controls.target.copy(center);

    var maxDim = Math.max(size.x, size.y, size.z);
    var fov = camera.fov * (Math.PI / 180);
    var dist = (maxDim / 2) / Math.tan(fov / 2) * 1.4;

    camera.position.copy(center);
    camera.position.z += dist;
    camera.near = maxDim * 0.001;
    camera.far = maxDim * 10;
    camera.updateProjectionMatrix();
    controls.update();
  }

  // ── Texture loader helper ────────────────────────────────────────────────
  // Disables mipmaps to prevent UV-island edge bleeding (dark seam lines).
  function loadTexture(url, flipY) {
    var tex = new THREE.TextureLoader().load(url);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.flipY = (flipY === undefined) ? true : flipY;
    tex.generateMipmaps = false;
    tex.minFilter = THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    return tex;
  }

  // ── Prepare model materials ──────────────────────────────────────────────
  function prepareModel(object, side) {
    // Exterior: DoubleSide (opaque backfaces visible from outside)
    // Interior: FrontSide (backfaces transparent, see through walls)
    var materialSide = (side === 'interior') ? THREE.FrontSide : THREE.DoubleSide;

    object.traverse(function (child) {
      if (child.isMesh && child.material) {
        var mats = Array.isArray(child.material) ? child.material : [child.material];
        mats.forEach(function (m) { m.side = materialSide; });
      }
    });

    scene.add(object);
    frameModel(object);
    hideProgress();

    extractHotspots(object, side || 'exterior');
    buildLabels();
    wireAnnotationPanel();
    wireHotspotBlocks();

    // Store exterior model; build toggle once ready if any interior model is set
    modelObjects.exterior = object;
    hotspotSets.exterior = hotspots.slice();
    if (interiorUrl || interiorObjUrl) buildToggle();
  }

  // ── Extract hotspots from JSON file or GLB Empties (fallback) ─────────────
  function extractHotspots(root, side) {
    var sideKey = side || 'exterior';

    // Primary: read hotspot positions from the JSON file exported by Blender addon
    if (jsonHotspots && jsonHotspots[sideKey] && jsonHotspots[sideKey].hotspots) {
      jsonHotspots[sideKey].hotspots.forEach(function (h) {
        var pos = h.position;
        var entry = {
          id: h.id,
          title: h.title || h.id,
          position: new THREE.Vector3(pos.x, pos.y, pos.z),
          cameraPos: null,
          lookAt: null,
          cameraMode: h.camera_mode || 'fly',
          labelObj: null,
          el: null,
        };
        if (h.camera) {
          entry.cameraPos = new THREE.Vector3(h.camera.x, h.camera.y, h.camera.z);
        }
        if (h.lookat) {
          entry.lookAt = new THREE.Vector3(h.lookat.x, h.lookat.y, h.lookat.z);
        }
        hotspots.push(entry);
      });
    } else {
      // Fallback: extract from GLB Empties (legacy pipeline)
      root.traverse(function (node) {
        var isHotspot = node.userData && (
          node.userData.hotspot === true ||
          node.userData.hotspot === 1 ||
          (typeof node.name === 'string' && node.name.toLowerCase().startsWith('hotspot_'))
        );
        if (!isHotspot) return;
        if (node.isMesh) return;

        var id = node.userData.hotspot_id || node.name;
        var pos = new THREE.Vector3();
        node.getWorldPosition(pos);

        var entry = {
          id: id,
          title: node.userData.title || id,
          position: pos,
          cameraPos: null,
          lookAt: null,
          cameraMode: node.userData.camera_mode || 'fly',
          labelObj: null,
          el: null,
        };

        if (node.userData.camera_x !== undefined) {
          entry.cameraPos = new THREE.Vector3(
            node.userData.camera_x, node.userData.camera_y, node.userData.camera_z
          );
        }
        if (node.userData.lookat_x !== undefined) {
          entry.lookAt = new THREE.Vector3(
            node.userData.lookat_x, node.userData.lookat_y, node.userData.lookat_z
          );
        }

        hotspots.push(entry);
      });
    }

    // CMS annotations: create hotspots for entries with manual coordinates
    cmsAnnotations.forEach(function (ann) {
      var existing = hotspots.find(function (h) { return h.id === ann.id; });
      if (!existing && ann.x !== undefined && ann.y !== undefined && ann.z !== undefined) {
        hotspots.push({
          id: ann.id,
          title: ann.title || ann.id,
          position: new THREE.Vector3(ann.x, ann.y, ann.z),
          cameraPos: null,
          lookAt: null,
          labelObj: null,
          el: null,
        });
      }
    });

    // merge CMS data (titles, camera_mode) into hotspot entries
    hotspots.forEach(function (hs) {
      var cms = cmsAnnotations.find(function (a) { return a.id === hs.id; });
      if (!cms) return;
      if (cms.title) hs.title = cms.title;
      if (cms.camera_mode) hs.cameraMode = cms.camera_mode;
    });
  }

  // ── Build CSS2D labels ───────────────────────────────────────────────────
  // addToScene defaults to true; pass false when pre-building for an inactive model
  function buildLabels(addToScene) {
    hotspots.forEach(function (hs, i) {
      var el = document.createElement('div');
      el.className = 'viewer-label';
      el.dataset.hotspot = hs.id;
      el.innerHTML =
        '<span class="viewer-label__dot"></span>' +
        '<span class="viewer-label__text">' + escHtml(hs.title) + '</span>';

      el.addEventListener('click', function (e) {
        e.stopPropagation();
        activateHotspot(hs.id);
      });

      // mobile: touchend fires before click; prevent ghost click and orbit theft
      el.addEventListener('touchend', function (e) {
        e.preventDefault();
        e.stopPropagation();
        activateHotspot(hs.id);
      }, { passive: false });

      var labelObj = new CSS2DObject(el);
      labelObj.position.copy(hs.position);
      // offset label slightly upward so it doesn't sit right on the surface
      labelObj.position.y += modelRadius * 0.01;
      labelObj.layers.set(0);
      if (addToScene !== false) scene.add(labelObj);

      hs.labelObj = labelObj;
      hs.el = el;
    });
  }

  // ── Wire POI expandable sections (left side, rendered by PHP) ────────────
  function wireAnnotationPanel() {
    // Legacy annotation entries
    var entries = document.querySelectorAll('.annotation-entry[data-hotspot]');
    entries.forEach(function (entry) {
      entry.addEventListener('click', function () {
        activateHotspot(entry.dataset.hotspot);
      });
    });

    // New expandable POI sections — activate hotspot when toggled open
    var poiSections = document.querySelectorAll('.poi-section[data-hotspot]');
    poiSections.forEach(function (section) {
      section.addEventListener('toggle', function () {
        if (section.open) {
          activateHotspot(section.dataset.hotspot);
        }
      });
    });
  }

  // ── Listen for goheritage:activate events from hotspot blocks in body text ─
  function wireHotspotBlocks() {
    container.addEventListener('goheritage:activate', function (e) {
      if (e.detail && e.detail.id) {
        activateHotspot(e.detail.id);
      }
    });
    // Also listen on the document so hotspot blocks outside the viewer
    // container (e.g. in the text column) can still reach us.
    document.addEventListener('goheritage:activate', function (e) {
      if (e.detail && e.detail.id) {
        activateHotspot(e.detail.id);
      }
    });
  }

  // ── Ext / Int toggle ─────────────────────────────────────────────────────
  function buildToggle() {
    var toggle = document.createElement('div');
    toggle.className = 'viewer-toggle';
    toggle.innerHTML =
      '<button class="viewer-toggle__btn is-active" data-side="exterior">Ext\u00e9rieur</button>' +
      '<button class="viewer-toggle__btn" data-side="interior">Int\u00e9rieur</button>';
    container.appendChild(toggle);
    toggle.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.viewer-toggle__btn') : null;
      if (btn && btn.dataset.side !== currentSide && !btn.disabled) {
        switchModel(btn.dataset.side);
      }
    });
  }

  // ── Update model bounds without re-framing the camera ───────────────────
  function updateModelBounds(object) {
    var box = new THREE.Box3().setFromObject(object);
    var size = box.getSize(new THREE.Vector3());
    modelCenter.copy(box.getCenter(new THREE.Vector3()));
    modelRadius = size.length() / 2;
    camera.near = size.length() * 0.001;
    camera.far = size.length() * 10;
    camera.updateProjectionMatrix();
  }

  // ── Switch between exterior / interior models ────────────────────────────
  function switchModel(side) {
    if (side === currentSide || !modelObjects.exterior) return;

    var btns = container.querySelectorAll('.viewer-toggle__btn');
    btns.forEach(function (b) { b.disabled = true; });

    // Capture camera so the angle is preserved across the swap
    var savedPos = camera.position.clone();
    var savedTarget = controls.target.clone();

    // Fade to black
    fadeEl.style.opacity = '1';

    setTimeout(function () {
      // Remove current model and its labels from the scene
      hotspotSets[currentSide].forEach(function (hs) {
        if (hs.labelObj) scene.remove(hs.labelObj);
      });
      if (activeHotspotId) { deactivateHotspot(activeHotspotId); activeHotspotId = null; }
      if (modelObjects[currentSide]) scene.remove(modelObjects[currentSide]);

      currentSide = side;
      btns.forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.side === side);
        b.disabled = false;
      });

      function showSide(obj) {
        scene.add(obj);
        updateModelBounds(obj);
        camera.position.copy(savedPos);
        controls.target.copy(savedTarget);
        controls.update();
        hotspots = hotspotSets[side];
        hotspots.forEach(function (hs) {
          if (hs.labelObj) scene.add(hs.labelObj);
        });
        // Fade back in
        fadeEl.style.opacity = '0';
      }

      if (modelObjects[side]) {
        showSide(modelObjects[side]);
      } else {
        loadInteriorModel(showSide);
      }
    }, 260);
  }

  // ── Dispatch to GLB or OBJ interior loader ───────────────────────────────
  // Prefer the converted GLB (same basename as OBJ); fall back to OBJ
  // (interiorGlbDerivedUrl already declared above for the early-return check)
  interiorGlbDerivedUrl = interiorGlbUrl || (interiorObjUrl
    ? interiorObjUrl.replace(/\.obj$/i, '.glb')
    : null);

  function loadInteriorModel(onReady) {
    if (interiorGlbDerivedUrl) {
      loadInteriorGlb(interiorGlbDerivedUrl, onReady, interiorObjUrl ? function () {
        loadInteriorObj(onReady);
      } : null);
    } else if (interiorObjUrl) {
      loadInteriorObj(onReady);
    }
  }

  // ── Lazy-load interior OBJ (first switch only) ────────────────────────────
  function loadInteriorObj(onReady) {
    container.querySelectorAll('.viewer-progress').forEach(function (el) { el.remove(); });
    var prog = document.createElement('div');
    prog.className = 'viewer-progress';
    prog.innerHTML =
      '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
      '<span class="viewer-progress-text">chargement\u2026</span>';
    container.appendChild(prog);
    var fill = prog.querySelector('.viewer-progress-fill');
    var text = prog.querySelector('.viewer-progress-text');

    var objLoader = new OBJLoader();
    var basePath = interiorObjUrl.substring(0, interiorObjUrl.lastIndexOf('/') + 1);
    var objFilename = interiorObjUrl.substring(interiorObjUrl.lastIndexOf('/') + 1);
    objLoader.setPath(basePath);

    objLoader.load(
      objFilename,
      function (model) {
        if (interiorTexUrl) {
          var matParams = { map: loadTexture(interiorTexUrl), side: THREE.FrontSide };
          if (interiorNormUrl) {
            matParams.normalMap = loadTexture(interiorNormUrl);
          }
          var mat = interiorNormUrl
            ? new THREE.MeshStandardMaterial(matParams)
            : new THREE.MeshBasicMaterial(matParams);
          model.traverse(function (child) {
            if (child.isMesh) child.material = mat;
          });
        } else {
          var fallback = new THREE.MeshBasicMaterial({ color: 0x888888, side: THREE.FrontSide });
          model.traverse(function (child) {
            if (child.isMesh) child.material = fallback;
          });
        }

        // Same hotspot extraction pattern as loadInteriorGlb
        var prevHotspots = hotspots;
        var prevRadius = modelRadius;
        var prevCenter = modelCenter.clone();

        // Blender exports Z-up; correct to Three.js Y-up
        model.rotation.x = -Math.PI / 2;

        var box = new THREE.Box3().setFromObject(model);
        modelRadius = box.getSize(new THREE.Vector3()).length() / 2;
        modelCenter.copy(box.getCenter(new THREE.Vector3()));

        hotspots = [];
        extractHotspots(model, 'interior');
        buildLabels(false);
        hotspotSets.interior = hotspots;

        hotspots = prevHotspots;
        modelRadius = prevRadius;
        modelCenter.copy(prevCenter);

        modelObjects.interior = model;

        prog.style.opacity = '0';
        setTimeout(function () { prog.remove(); }, 400);
        onReady(model);
      },
      function (xhr) {
        if (xhr.total > 0) {
          var pct = Math.round((xhr.loaded / xhr.total) * 100);
          fill.style.width = pct + '%';
          text.textContent = 'chargement\u2026 ' + pct + '%';
        }
      },
      function (err) {
        console.error('interior obj load error:', err);
        text.textContent = 'erreur de chargement';
      }
    );
  }

  // ── Lazy-load the interior GLB (first switch only) ───────────────────────
  function loadInteriorGlb(url, onReady, onFail) {
    container.querySelectorAll('.viewer-progress').forEach(function (el) { el.remove(); });
    var prog = document.createElement('div');
    prog.className = 'viewer-progress';
    prog.innerHTML =
      '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
      '<span class="viewer-progress-text">chargement\u2026</span>';
    container.appendChild(prog);
    var fill = prog.querySelector('.viewer-progress-fill');
    var text = prog.querySelector('.viewer-progress-text');

    var loader = new GLTFLoader();
    if (dracoPath) {
      var dl = new DRACOLoader();
      dl.setDecoderPath(dracoPath);
      loader.setDRACOLoader(dl);
    }

    loader.load(
      url,
      function (gltf) {
        var model = gltf.scene;

        // Apply texture if provided (GLB converted from OBJ won't have embedded texture)
        if (interiorTexUrl) {
          var matParams = { map: loadTexture(interiorTexUrl, false), side: THREE.FrontSide };
          if (interiorNormUrl) {
            matParams.normalMap = loadTexture(interiorNormUrl, false);
          }
          var mat = interiorNormUrl
            ? new THREE.MeshStandardMaterial(matParams)
            : new THREE.MeshBasicMaterial(matParams);
          model.traverse(function (child) {
            if (child.isMesh) child.material = mat;
          });
        } else {
          model.traverse(function (child) {
            if (child.isMesh && child.material) {
              [].concat(child.material).forEach(function (m) { m.side = THREE.FrontSide; });
            }
          });
        }

        // Blender exports Z-up; correct to Three.js Y-up
        model.rotation.x = -Math.PI / 2;

        // Temporarily borrow globals to build hotspot labels without touching the scene
        var prevHotspots = hotspots;
        var prevRadius = modelRadius;
        var prevCenter = modelCenter.clone();

        var box = new THREE.Box3().setFromObject(model);
        modelRadius = box.getSize(new THREE.Vector3()).length() / 2;
        modelCenter.copy(box.getCenter(new THREE.Vector3()));

        hotspots = [];
        extractHotspots(model, 'interior');
        buildLabels(false); // create CSS2DObjects, don't add to scene yet
        hotspotSets.interior = hotspots;

        // Restore exterior state
        hotspots = prevHotspots;
        modelRadius = prevRadius;
        modelCenter.copy(prevCenter);

        modelObjects.interior = model;

        prog.style.opacity = '0';
        setTimeout(function () { prog.remove(); }, 400);

        onReady(model);
      },
      function (xhr) {
        if (xhr.total > 0) {
          var pct = Math.round((xhr.loaded / xhr.total) * 100);
          fill.style.width = pct + '%';
          text.textContent = 'chargement\u2026 ' + pct + '%';
        }
      },
      function (err) {
        console.warn('interior glb load failed, trying obj:', err);
        prog.remove();
        if (onFail) onFail();
        else text.textContent = 'erreur de chargement';
      }
    );
  }

  // ── Activate a hotspot ───────────────────────────────────────────────────
  function activateHotspot(id) {
    var hs = hotspots.find(function (h) { return h.id === id; });
    if (!hs) return;

    // deactivate previous
    if (activeHotspotId) deactivateHotspot(activeHotspotId);
    activeHotspotId = id;

    // highlight 3D label
    if (hs.el) hs.el.classList.add('is-active');

    // highlight panel entry (desktop)
    var panelEntry = document.querySelector('.annotation-entry[data-hotspot="' + id + '"]');
    if (panelEntry) {
      panelEntry.classList.add('is-active');
      panelEntry.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // On mobile: show POI popup with the matching section's content
    if (isMobile) {
      showPoiPopup(id);
    }

    // camera behaviour depends on mode
    var targetPos = hs.position.clone();

    // Compute the camera destination.
    // If a position was stored in Blender, use it; otherwise approach
    // from the direction of hotspot → model center at a sensible distance.
    var cameraTarget;
    if (hs.cameraPos) {
      cameraTarget = hs.cameraPos.clone();
    } else {
      var dir = targetPos.clone().sub(modelCenter).normalize();
      if (dir.length() < 0.01) dir.set(0, 0, 1);
      cameraTarget = targetPos.clone().add(dir.multiplyScalar(modelRadius * 0.6));
    }

    var lookAtTarget = hs.lookAt ? hs.lookAt.clone() : targetPos.clone();

    if (hs.cameraMode === 'auto-orbit') {
      // Auto-orbit: fly to stored position, then start 360° auto-orbit
      flyTo(cameraTarget, lookAtTarget, 1.2, function () {
        startAutoOrbit(lookAtTarget);
      });
    } else {
      // Both "fly" and "orbit" fly to the stored Blender position.
      // "orbit" simply re-centers the orbit pivot so subsequent user
      // interaction orbits around the hotspot naturally.
      flyTo(cameraTarget, lookAtTarget, 1.2);
    }
  }

  function deactivateHotspot(id) {
    var hs = hotspots.find(function (h) { return h.id === id; });
    if (hs && hs.el) hs.el.classList.remove('is-active');
    if (isMobile) hidePoiPopup();

    var panelEntry = document.querySelector('.annotation-entry[data-hotspot="' + id + '"]');
    if (panelEntry) panelEntry.classList.remove('is-active');
  }

  // ── Smooth camera fly-to ─────────────────────────────────────────────────
  function flyTo(targetCamPos, targetLookAt, duration, onComplete) {
    // cancel any existing fly or auto-orbit animation
    if (flyAnimation) cancelAnimationFrame(flyAnimation);
    stopAutoOrbit();

    var startPos = camera.position.clone();
    var startTarget = controls.target.clone();
    var startTime = performance.now();
    var dur = (duration || 1.2) * 1000;

    controls.enabled = false;

    function tick(now) {
      var elapsed = now - startTime;
      var t = Math.min(elapsed / dur, 1);
      // easeInOutCubic
      var ease = t < 0.5
        ? 4 * t * t * t
        : 1 - Math.pow(-2 * t + 2, 3) / 2;

      camera.position.lerpVectors(startPos, targetCamPos, ease);
      controls.target.lerpVectors(startTarget, targetLookAt, ease);
      controls.update();

      if (t < 1) {
        flyAnimation = requestAnimationFrame(tick);
      } else {
        flyAnimation = null;
        controls.enabled = true;
        if (typeof onComplete === 'function') onComplete();
      }
    }

    flyAnimation = requestAnimationFrame(tick);
  }

  // ── Auto-orbit (360° rotation around a pivot) ────────────────────────────
  var autoOrbitRAF = null;

  function startAutoOrbit(pivot) {
    stopAutoOrbit();

    var radius = camera.position.distanceTo(pivot);
    var offset = camera.position.clone().sub(pivot);
    var angle = Math.atan2(offset.x, offset.z);
    var elevY = offset.y;
    var speed = 0.3; // radians per second

    var lastTime = performance.now();

    function orbitTick(now) {
      var dt = (now - lastTime) / 1000;
      lastTime = now;
      angle += speed * dt;

      camera.position.set(
        pivot.x + Math.sin(angle) * radius,
        pivot.y + elevY,
        pivot.z + Math.cos(angle) * radius
      );
      controls.target.copy(pivot);
      controls.update();

      autoOrbitRAF = requestAnimationFrame(orbitTick);
    }

    autoOrbitRAF = requestAnimationFrame(orbitTick);

    // Cancel auto-orbit on any user interaction
    var cancelEvents = ['pointerdown', 'wheel', 'touchstart'];
    function cancelAutoOrbit() {
      stopAutoOrbit();
      cancelEvents.forEach(function (ev) {
        renderer.domElement.removeEventListener(ev, cancelAutoOrbit);
      });
    }
    cancelEvents.forEach(function (ev) {
      renderer.domElement.addEventListener(ev, cancelAutoOrbit, { once: true });
    });
  }

  function stopAutoOrbit() {
    if (autoOrbitRAF) {
      cancelAnimationFrame(autoOrbitRAF);
      autoOrbitRAF = null;
    }
  }

  // ── Mobile POI popup ─────────────────────────────────────────────────────
  var poiPopup = document.getElementById('poi-popup');
  var poiPopupTitle = document.getElementById('poi-popup-title');
  var poiPopupBody = document.getElementById('poi-popup-body');
  var poiPopupClose = document.getElementById('poi-popup-close');

  function showPoiPopup(hotspotId) {
    if (!poiPopup || !poiPopupTitle || !poiPopupBody) return;

    // Find the matching POI section rendered by the poi block
    var section = document.querySelector('.poi-section[data-hotspot="' + hotspotId + '"]');
    if (!section) return;

    var titleEl = section.querySelector('.poi-section__title');
    var bodyEl = section.querySelector('.poi-section__body');

    poiPopupTitle.textContent = titleEl ? titleEl.textContent : hotspotId;
    poiPopupBody.innerHTML = bodyEl ? bodyEl.innerHTML : '';

    poiPopup.classList.add('is-visible');
    poiPopup.setAttribute('aria-hidden', 'false');
  }

  function hidePoiPopup() {
    if (!poiPopup) return;
    poiPopup.classList.remove('is-visible');
    poiPopup.setAttribute('aria-hidden', 'true');
  }

  if (poiPopupClose) {
    poiPopupClose.addEventListener('click', function (e) {
      e.stopPropagation();
      hidePoiPopup();
    });
  }

  // ── Derive the converted GLB url from the OBJ url (same basename, .glb ext) ─
  var exteriorGlbUrl = glbUrl || (objUrl
    ? objUrl.replace(/\.obj$/i, '.glb')
    : null);

  // ── Load exterior model — GLB (converted from OBJ) preferred, OBJ fallback ─
  function loadExteriorGlb(onFail) {
    var loader = new GLTFLoader();
    if (dracoPath) {
      var dl = new DRACOLoader();
      dl.setDecoderPath(dracoPath);
      loader.setDRACOLoader(dl);
    }
    loader.load(
      exteriorGlbUrl,
      function (gltf) {
        var model = gltf.scene;
        // Blender exports Z-up; correct to Three.js Y-up
        if (objUrl) model.rotation.x = -Math.PI / 2;
        // Apply texture when GLB was converted from OBJ (no embedded texture)
        if (texUrl && objUrl) {
          var matParams = { map: loadTexture(texUrl, false), side: THREE.DoubleSide };
          if (normUrl) {
            matParams.normalMap = loadTexture(normUrl, false);
          }
          var mat = normUrl
            ? new THREE.MeshStandardMaterial(matParams)
            : new THREE.MeshBasicMaterial(matParams);
          model.traverse(function (child) {
            if (child.isMesh) child.material = mat;
          });
        }
        prepareModel(model, 'exterior');
      },
      function (xhr) { updateProgress(xhr.loaded, xhr.total); },
      function (err) {
        console.warn('glb load failed, falling back to obj:', err);
        if (onFail) onFail();
      }
    );
  }

  function loadExteriorObj() {
    var manager = new THREE.LoadingManager();
    manager.onError = function (url) {
      progressText.textContent = 'erreur de chargement';
      console.error('failed to load:', url);
    };

    var objLoader = new OBJLoader(manager);
    var basePath = objUrl.substring(0, objUrl.lastIndexOf('/') + 1);
    var objFilename = objUrl.substring(objUrl.lastIndexOf('/') + 1);
    objLoader.setPath(basePath);

    objLoader.load(
      objFilename,
      function (object) {
        if (texUrl) {
          var matParams = { map: loadTexture(texUrl), side: THREE.DoubleSide };
          if (normUrl) {
            matParams.normalMap = loadTexture(normUrl);
          }
          var mat = normUrl
            ? new THREE.MeshStandardMaterial(matParams)
            : new THREE.MeshBasicMaterial(matParams);
          object.traverse(function (child) {
            if (child.isMesh) child.material = mat;
          });
        } else {
          var fallback = new THREE.MeshBasicMaterial({ color: 0x888888, side: THREE.DoubleSide });
          object.traverse(function (child) { if (child.isMesh) child.material = fallback; });
        }
        // Blender exports Z-up; correct to Three.js Y-up
        object.rotation.x = -Math.PI / 2;
        prepareModel(object, 'exterior');
      },
      function (xhr) { updateProgress(xhr.loaded, xhr.total); },
      function (err) { console.error('obj load error:', err); }
    );
  }

  function startLoading() {
    if (exteriorGlbUrl) {
      loadExteriorGlb(objUrl ? loadExteriorObj : null);
    } else if (objUrl) {
      loadExteriorObj();
    } else if (interiorGlbDerivedUrl || interiorObjUrl) {
      // No exterior model — load interior directly as the starting view
      loadInteriorModel(function (object) {
        modelObjects['interior'] = object;
        scene.add(object);
        frameModel(object);
        hideProgress();
      });
    }
  }

  // Fetch JSON hotspots first, then load the model
  if (hotspotsJsonUrl) {
    fetch(hotspotsJsonUrl)
      .then(function (r) { return r.json(); })
      .then(function (data) { jsonHotspots = data; })
      .catch(function (err) { console.warn('hotspots json load failed:', err); })
      .finally(startLoading);
  } else {
    startLoading();
  }

  // ── Adaptive pixel ratio (performance) ───────────────────────────────────
  var frameCount = 0;
  var lastFpsCheck = performance.now();
  var currentPixelRatio = isMobile ? 1 : Math.min(window.devicePixelRatio, 2);

  function checkPerformance() {
    if (isMobile) return; // already locked to 1 on mobile
    frameCount++;
    var now = performance.now();
    var delta = now - lastFpsCheck;
    if (delta >= 2000) {
      var fps = (frameCount / delta) * 1000;
      frameCount = 0;
      lastFpsCheck = now;

      // drop pixel ratio if FPS is too low
      if (fps < 25 && currentPixelRatio > 1) {
        currentPixelRatio = 1;
        renderer.setPixelRatio(1);
      }
    }
  }

  // ── Render loop ──────────────────────────────────────────────────────────
  function animate() {
    requestAnimationFrame(animate);

    // Dynamic controls speed — scale damping, pan, and zoom relative to
    // camera-to-target distance so close-up navigation stays responsive.
    var dist = camera.position.distanceTo(controls.target);
    var ratio = Math.max(dist / Math.max(modelRadius, 1), 0.05);
    controls.dampingFactor = 0.08 * Math.max(ratio, 0.3);
    controls.panSpeed = Math.max(ratio * 0.8, 0.15);
    controls.zoomSpeed = Math.max(ratio * 1.0, 0.25);

    controls.update();
    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
    checkPerformance();
  }
  animate();

  // ── Handle resize ────────────────────────────────────────────────────────
  var observer = new ResizeObserver(function () {
    var w = container.clientWidth;
    var h = container.clientHeight;
    renderer.setSize(w, h);
    labelRenderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  });
  observer.observe(container);

  // ── tap on empty canvas deactivates active hotspot ───────────────────────
  // use pointerdown/pointerup so we can distinguish a tap from an orbit drag
  // orbitControls consumes pointermove, but we track displacement ourselves
  var _pdX = 0, _pdY = 0;

  renderer.domElement.addEventListener('pointerdown', function (e) {
    _pdX = e.clientX;
    _pdY = e.clientY;
  });

  renderer.domElement.addEventListener('pointerup', function (e) {
    var dx = e.clientX - _pdX;
    var dy = e.clientY - _pdY;
    // treat as tap only if the pointer barely moved (< 6 px — not an orbit drag)
    if (Math.sqrt(dx * dx + dy * dy) < 6 && activeHotspotId) {
      deactivateHotspot(activeHotspotId);
      activeHotspotId = null;
    }
  });
}

// ── Utility ──────────────────────────────────────────────────────────────────

function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

// ── Auto-init ────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('viewer-3d');
  if (container) initViewer(container);
});
