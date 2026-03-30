<?php

/**
 * GoHéritage — Hotspot Detector plugin
 *
 * Parses GLB files (pure PHP, no Node.js) and populates the annotations
 * structure field on the project page.
 *
 * Auto-runs when a GLB is uploaded or created (e.g. after OBJ conversion).
 * Also exposes a POST API route used by the panel "Detect" button.
 */

use Kirby\Cms\App as Kirby;
use Kirby\Data\Yaml;

Kirby::plugin('goheritage/hotspot-detector', [

    // ── Custom field: "Detect" button in the panel ────────────────────────
    'fields' => [
        'hotspot-detect' => [
            'computed' => [
                // page ID passed to the Vue component so it knows which page to update
                'pageId' => function () {
                    return $this->model()->id();
                },
                // tells the component how many annotations already exist
                'existingCount' => function () {
                    $model = $this->model();
                    if ($model->annotations()->isNotEmpty()) {
                        return $model->annotations()->toStructure()->count();
                    }
                    return 0;
                },
            ],
        ],
    ],

    // ── API route: called by panel button ─────────────────────────────────
    'api' => [
        'routes' => [
            [
                'pattern' => 'goheritage/detect-hotspots/(:any)',
                'method'  => 'POST',
                'action'  => function ($rawId) {
                    // Kirby panel encodes page IDs with + instead of /
                    $pageId = str_replace('+', '/', urldecode($rawId));
                    $page   = kirby()->page($pageId);

                    if (!$page) {
                        return ['status' => 'error', 'message' => 'Page not found: ' . $pageId];
                    }

                    $result = detectAndSaveHotspots($page);
                    return $result;
                },
            ],
        ],
    ],

    // ── Hooks: auto-detect when a GLB lands on a project page ─────────────
    'hooks' => [
        'file.create:after' => function ($file) {
            if (strtolower($file->extension()) === 'glb') {
                $page = $file->parent();
                if ($page && $page->template()->name() === 'project') {
                    detectAndSaveHotspots($page, $file->root());
                }
            }
        },
        'file.replace:after' => function ($newFile, $oldFile) {
            if (strtolower($newFile->extension()) === 'glb') {
                $page = $newFile->parent();
                if ($page && $page->template()->name() === 'project') {
                    detectAndSaveHotspots($page, $newFile->root());
                }
            }
        },
    ],
]);


// ── Core: parse GLB + merge into page annotations ──────────────────────────

/**
 * Parse a GLB file for hotspot Empties and merge into the page's
 * annotations structure field, preserving any existing descriptions.
 *
 * @param \Kirby\Cms\Page $page
 * @param string|null     $glbPath  explicit path; if null, auto-detected
 * @return array  { count: int, added: int, skipped: int, hotspots: [] }
 */
function detectAndSaveHotspots($page, $glbPath = null) {

    // ── find the GLB file ──────────────────────────────────────────────────
    if (!$glbPath) {
        $glbFile = $page->files()->filterBy('extension', 'glb')->first();
        if (!$glbFile) {
            return ['status' => 'ok', 'count' => 0, 'added' => 0, 'skipped' => 0,
                    'message' => 'No GLB file found on this page.', 'hotspots' => []];
        }
        $glbPath = $glbFile->root();
    }

    // ── parse hotspots from the GLB binary ────────────────────────────────
    $hotspots = parseGlbHotspots($glbPath);

    // ── read existing annotations from the page ───────────────────────────
    $existing = [];
    if ($page->annotations()->isNotEmpty()) {
        foreach ($page->annotations()->toStructure() as $ann) {
            $id = $ann->hotspot_id()->value();
            $existing[$id] = [
                'hotspot_id'  => $id,
                'title'       => $ann->title()->value(),
                'description' => $ann->description()->value(),
            ];
        }
    }

    // ── merge: keep existing descriptions, add new, preserve order ────────
    $merged  = [];
    $added   = 0;
    $skipped = 0;

    foreach ($hotspots as $hs) {
        if (isset($existing[$hs['id']])) {
            // already exists — preserve description, optionally update title
            // (Blender title wins only if user hasn't touched the CMS title)
            $existing[$hs['id']]['title'] = $existing[$hs['id']]['title'] ?: $hs['title'];
            $merged[] = $existing[$hs['id']];
            $skipped++;
        } else {
            $merged[] = [
                'hotspot_id'  => $hs['id'],
                'title'       => $hs['title'],
                'description' => '',
            ];
            $added++;
        }
    }

    // ── persist ───────────────────────────────────────────────────────────
    if (!empty($merged)) {
        try {
            // impersonate kirby superuser so this works regardless of the
            // current user's role (e.g. author who can't publish)
            kirby()->impersonate('kirby');
            $page->update([
                'annotations' => Yaml::encode($merged),
            ]);
        } catch (\Throwable $e) {
            error_log('[hotspot-detector] failed to update annotations: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage(),
                    'count' => 0, 'added' => 0, 'skipped' => 0, 'hotspots' => []];
        }
    }

    return [
        'status'   => 'ok',
        'count'    => count($merged),
        'added'    => $added,
        'skipped'  => $skipped,
        'hotspots' => $hotspots,
        'message'  => count($merged) . ' hotspot(s) detected (' . $added . ' new, ' . $skipped . ' existing preserved)',
    ];
}

/**
 * Parse a GLB binary and return all hotspot Empty objects found in it.
 * No Node.js required — reads the GLB JSON chunk directly.
 *
 * GLB format:
 *   Bytes  0- 3  magic "glTF" = 0x46546C67
 *   Bytes  4- 7  version (uint32 LE, should be 2)
 *   Bytes  8-11  total file length (uint32 LE)
 *   Bytes 12-15  JSON chunk length (uint32 LE)
 *   Bytes 16-19  JSON chunk type  (0x4E4F534A = "JSON")
 *   Bytes 20-..  JSON chunk data
 *
 * @param string $glbPath absolute path to the .glb file
 * @return array  array of ['id' => string, 'title' => string]
 */
function parseGlbHotspots($glbPath) {
    if (!file_exists($glbPath) || !is_readable($glbPath)) {
        return [];
    }

    // read just the header + JSON chunk (skip the binary buffer)
    $header = file_get_contents($glbPath, false, null, 0, 20);
    if (strlen($header) < 20) return [];

    $magic = unpack('V', substr($header, 0, 4))[1];
    if ($magic !== 0x46546C67) {
        error_log('[hotspot-detector] not a valid GLB file: ' . $glbPath);
        return [];
    }

    $jsonLen = unpack('V', substr($header, 12, 4))[1];
    if ($jsonLen <= 0 || $jsonLen > 50 * 1024 * 1024) return []; // sanity check

    $jsonStr = file_get_contents($glbPath, false, null, 20, $jsonLen);
    if (!$jsonStr) return [];

    $gltf = json_decode($jsonStr, true);
    if (!is_array($gltf) || !isset($gltf['nodes'])) return [];

    $hotspots = [];

    foreach ($gltf['nodes'] as $node) {
        $extras = isset($node['extras']) && is_array($node['extras']) ? $node['extras'] : [];
        $name   = $node['name'] ?? '';

        // a hotspot is either tagged in extras OR named "hotspot_*"
        $isHotspot = !empty($extras['hotspot'])
            || strncasecmp($name, 'hotspot_', 8) === 0;

        if (!$isHotspot) continue;

        // skip nodes that have mesh data — those are geometry, not Empties
        if (isset($node['mesh'])) continue;

        $id    = $extras['hotspot_id'] ?? $name;
        $title = $extras['title']      ?? $name;

        if (empty($id)) continue;

        $hotspots[] = [
            'id'    => $id,
            'title' => $title,
        ];
    }

    return $hotspots;
}
