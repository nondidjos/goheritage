# @goheritage/panoviewer

Self-contained Matterport-style panorama + dollhouse viewer (Three.js). Ships
as both a Kirby site plugin and a portable ES-module package.

## Layout

```
site/plugins/panoviewer/
├─ index.php                       Kirby plugin registration
├─ package.json                    npm-style metadata
├─ README.md
└─ assets/
   ├─ panoviewer.css               Stylesheet
   ├─ panoviewer.js                Main entry — exports PanoViewer, etc.
   ├─ goheritage-bridge.js         Boot helper: data-attr → PanoViewer
   └─ panoviewer/                  Internal modules
      ├─ cube-mesh.js
      ├─ dollhouse.js
      ├─ groups.js
      ├─ icons.js
      ├─ lru.js
      └─ measure.js
```

## Use from a Kirby template

```php
<?= css(panoviewerAsset('panoviewer.css')) ?>
<script type="module">
  import { boot } from '<?= panoviewerAsset('goheritage-bridge.js') ?>';
  boot(document.getElementById('pano-viewer'));
</script>
```

The bridge reads `data-pano-urls`, `data-pano-scenes`, `data-pano-rewrite`,
`data-goheritage-url`, `data-model-url`, `data-model-texture`,
`data-marker-offset-{x,y,z}`, `data-draco-path` from the host element.

## Use from a generic ES module

```js
import { PanoViewer } from '@goheritage/panoviewer';
import '@goheritage/panoviewer/style.css';

const viewer = new PanoViewer(document.getElementById('host'), { /* opts */ });
viewer.addScene({ id: 'a', type: 'cube', faces: [...] });
viewer.loadScene('a');
```

A `three` peer dependency is required (importmap or bundler resolves
`three` and `three/addons/`).

## Bridge helpers (named exports)

```js
import {
  boot,             // (el, opts?) → PanoViewer
  groupSkyboxes,    // (items, rewrite?) → { cubeScenes, equirectScenes }
  linkByPosition,   // (scenes, maxDist?) — auto nav hotspots
  enrichFromJson,   // (scenes, ghJson) — overlay positions/quat/title
} from '@goheritage/panoviewer/bridge';
```
