#!/usr/bin/env node
/**
 * GoHéritage — List hotspots embedded in a GLB file
 *
 * Usage:
 *   node scripts/list-hotspots.mjs path/to/model.glb
 *
 * This reads the GLB file, finds all Empty objects (nodes without mesh data)
 * that have hotspot-related userData or names starting with "hotspot_",
 * and prints their IDs, titles, positions, and optional camera positions.
 *
 * Useful for:
 *   - Verifying hotspot data before uploading
 *   - Getting the IDs to enter in the Kirby CMS annotations panel
 */

import { readFileSync } from 'fs';
import { resolve } from 'path';

const file = process.argv[2];
if (!file) {
  console.error('Usage: node scripts/list-hotspots.mjs <path-to-glb>');
  process.exit(1);
}

const filepath = resolve(file);
const buffer = readFileSync(filepath);

// Parse GLB container
const magic = buffer.readUInt32LE(0);
if (magic !== 0x46546C67) {
  console.error('Not a valid GLB file (bad magic)');
  process.exit(1);
}

const jsonLen = buffer.readUInt32LE(12);
const jsonStr = buffer.slice(20, 20 + jsonLen).toString('utf8');
const gltf = JSON.parse(jsonStr);

const nodes = gltf.nodes || [];
const hotspots = [];

for (const node of nodes) {
  const extras = node.extras || {};
  const name = node.name || '';
  const isHotspot =
    extras.hotspot === true ||
    extras.hotspot === 1 ||
    name.toLowerCase().startsWith('hotspot_');

  // skip nodes that reference a mesh (they're geometry, not Empties)
  if (!isHotspot || node.mesh !== undefined) continue;

  const translation = node.translation || [0, 0, 0];

  const entry = {
    id: extras.hotspot_id || name,
    title: extras.title || name,
    position: {
      x: +translation[0].toFixed(4),
      y: +translation[1].toFixed(4),
      z: +translation[2].toFixed(4),
    },
  };

  if (extras.camera_x !== undefined) {
    entry.camera = {
      x: +extras.camera_x,
      y: +extras.camera_y,
      z: +extras.camera_z,
    };
  }

  hotspots.push(entry);
}

if (hotspots.length === 0) {
  console.log('No hotspots found in this GLB file.');
  console.log('');
  console.log('To add hotspots:');
  console.log('  1. Install the Blender addon: blender/goheritage_hotspots.py');
  console.log('  2. Place Empty objects named "hotspot_01", "hotspot_02", etc.');
  console.log('  3. Export GLB with "Custom Properties" / "Export Extras" enabled.');
  process.exit(0);
}

console.log(`Found ${hotspots.length} hotspot(s) in ${file}:\n`);

const colId = Math.max(4, ...hotspots.map(h => h.id.length)) + 2;
const colTitle = Math.max(6, ...hotspots.map(h => h.title.length)) + 2;

console.log(
  'ID'.padEnd(colId) +
  'Title'.padEnd(colTitle) +
  'Position (x, y, z)'.padEnd(30) +
  'Camera'
);
console.log('-'.repeat(colId + colTitle + 50));

for (const hs of hotspots) {
  const pos = `(${hs.position.x}, ${hs.position.y}, ${hs.position.z})`;
  const cam = hs.camera
    ? `(${hs.camera.x}, ${hs.camera.y}, ${hs.camera.z})`
    : '—';
  console.log(
    hs.id.padEnd(colId) +
    hs.title.padEnd(colTitle) +
    pos.padEnd(30) +
    cam
  );
}

console.log('');
console.log('Copy these IDs into the Kirby panel under "Points d\'intérêt" tab.');
