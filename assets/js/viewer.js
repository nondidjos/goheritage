// three.js viewer for photogrammetry models (glb/gltf with draco, or obj+mtl fallback)
// loaded as an es module via import map defined in the page header

import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { MTLLoader } from 'three/addons/loaders/MTLLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

function initViewer(container) {
  // read model urls from data attributes — glb takes priority over obj
  const glbUrl = container.dataset.glb || null;
  const objUrl = container.dataset.obj || null;
  const mtlUrl = container.dataset.mtl || null;
  const texUrl = container.dataset.texture || null;
  const dracoPath = container.dataset.dracoPath || null;

  if (!glbUrl && !objUrl) return;

  // renderer
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  container.appendChild(renderer.domElement);

  // scene
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1a1a1a);

  // flat ambient light — pre-baked photogrammetry textures don't need shading
  scene.add(new THREE.AmbientLight(0xffffff, 1.0));

  // camera
  const camera = new THREE.PerspectiveCamera(
    50,
    container.clientWidth / container.clientHeight,
    0.1,
    10000
  );

  // orbit controls
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.screenSpacePanning = true;
  controls.maxDistance = 5000;

  // progress overlay
  const progress = document.createElement('div');
  progress.className = 'viewer-progress';
  progress.innerHTML = `
    <div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>
    <span class="viewer-progress-text">chargement du modèle…</span>
  `;
  container.appendChild(progress);

  const progressFill = progress.querySelector('.viewer-progress-fill');
  const progressText = progress.querySelector('.viewer-progress-text');

  function updateProgress(loaded, total) {
    if (total > 0) {
      const pct = Math.round((loaded / total) * 100);
      progressFill.style.width = pct + '%';
      progressText.textContent = `chargement… ${pct}%`;
    }
  }

  function hideProgress() {
    progress.style.opacity = '0';
    setTimeout(() => progress.remove(), 400);
  }

  // frame the camera to fit the model
  function frameModel(object) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());

    controls.target.copy(center);

    const maxDim = Math.max(size.x, size.y, size.z);
    const fov = camera.fov * (Math.PI / 180);
    const dist = (maxDim / 2) / Math.tan(fov / 2) * 1.4;

    camera.position.copy(center);
    camera.position.z += dist;
    camera.near = maxDim * 0.001;
    camera.far = maxDim * 10;
    camera.updateProjectionMatrix();

    controls.update();
  }

  // after loading, fix material settings for photogrammetry viewing
  function prepareModel(object) {
    object.traverse((child) => {
      if (child.isMesh && child.material) {
        const mats = Array.isArray(child.material) ? child.material : [child.material];
        mats.forEach((m) => {
          // let users see inside (chapel is scanned from the interior)
          m.side = THREE.FrontSide;
        });
      }
    });

    scene.add(object);
    frameModel(object);
    hideProgress();
  }

  // -- glb/gltf path (preferred — smaller, draco support) --
  if (glbUrl) {
    const gltfLoader = new GLTFLoader();

    // set up draco decoder if a decoder path was provided
    if (dracoPath) {
      const dracoLoader = new DRACOLoader();
      dracoLoader.setDecoderPath(dracoPath);
      gltfLoader.setDRACOLoader(dracoLoader);
    }

    gltfLoader.load(
      glbUrl,
      (gltf) => {
        const model = gltf.scene;

        // if there's an explicit texture override, apply it
        if (texUrl) {
          const tex = new THREE.TextureLoader().load(texUrl);
          tex.colorSpace = THREE.SRGBColorSpace;
          tex.flipY = false; // glTF requires flipped UVs compared to standard WebGL/OBJ
          const mat = new THREE.MeshBasicMaterial({ map: tex, side: THREE.FrontSide });
          model.traverse((child) => {
            if (child.isMesh) child.material = mat;
          });
        }

        prepareModel(model);
      },
      (xhr) => updateProgress(xhr.loaded, xhr.total),
      (err) => {
        console.error('glb load error:', err);
        progressText.textContent = 'erreur de chargement';
      }
    );

  // -- obj+mtl fallback path --
  } else if (objUrl) {
    const manager = new THREE.LoadingManager();
    manager.onLoad = hideProgress;
    manager.onError = (url) => {
      progressText.textContent = 'erreur de chargement';
      console.error('failed to load:', url);
    };

    function loadObj(materials) {
      const objLoader = new OBJLoader(manager);
      if (materials) {
        materials.preload();
        objLoader.setMaterials(materials);
      }

      const basePath = objUrl.substring(0, objUrl.lastIndexOf('/') + 1);
      objLoader.setPath(basePath);
      const objFilename = objUrl.substring(objUrl.lastIndexOf('/') + 1);

      objLoader.load(
        objFilename,
        (object) => {
          // explicit texture always wins over mtl references
          if (texUrl) {
            const tex = new THREE.TextureLoader().load(texUrl);
            tex.colorSpace = THREE.SRGBColorSpace;
            const mat = new THREE.MeshBasicMaterial({ map: tex, side: THREE.FrontSide });
            object.traverse((child) => {
              if (child.isMesh) child.material = mat;
            });
          } else if (!materials) {
            const fallback = new THREE.MeshBasicMaterial({
              color: 0x888888,
              side: THREE.FrontSide
            });
            object.traverse((child) => {
              if (child.isMesh) child.material = fallback;
            });
          }

          prepareModel(object);
        },
        (xhr) => updateProgress(xhr.loaded, xhr.total),
        (err) => console.error('obj load error:', err)
      );
    }

    if (mtlUrl && !texUrl) {
      const mtlLoader = new MTLLoader(manager);
      const mtlBase = mtlUrl.substring(0, mtlUrl.lastIndexOf('/') + 1);
      mtlLoader.setPath(mtlBase);
      const mtlFilename = mtlUrl.substring(mtlUrl.lastIndexOf('/') + 1);

      mtlLoader.load(
        mtlFilename,
        (materials) => loadObj(materials),
        undefined,
        (err) => {
          console.warn('mtl load failed, falling back to obj-only:', err);
          loadObj(null);
        }
      );
    } else {
      loadObj(null);
    }
  }

  // render loop
  function animate() {
    requestAnimationFrame(animate);
    controls.update();
    renderer.render(scene, camera);
  }
  animate();

  // handle container resize
  const observer = new ResizeObserver(() => {
    const w = container.clientWidth;
    const h = container.clientHeight;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  });
  observer.observe(container);
}

// auto-init on dom ready
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('viewer-3d');
  if (container) initViewer(container);
});
