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
                'auth'    => false,
                'action'  => function ($rawId) {
                    $kirby = kirby();
                    if (!$kirby->user()) {
                        return ['status' => 'error', 'message' => 'Unauthorized'];
                    }

                    // Kirby panel encodes page IDs with + instead of /
                    $pageId = str_replace('+', '/', urldecode($rawId));
                    $page   = $kirby->page($pageId);

                    if (!$page) {
                        return ['status' => 'error', 'message' => 'Page not found: ' . $pageId];
                    }

                    $result = detectAndSaveHotspots($page);
                    return $result;
                },
            ],
        ],
    ],

    // ── Hooks: auto-detect when a JSON lands on a project page ─────────────
    'hooks' => [
        'file.create:after' => function ($file) {
            $ext = strtolower($file->extension());
            $page = $file->parent();
            if ($page && $page->template()->name() === 'project') {
                if ($ext === 'json') {
                    detectAndSaveHotspots($page, $file->root(), 'json');
                } elseif ($ext === 'glb') {
                    detectAndSaveHotspots($page, $file->root(), 'glb');
                }
            }
        },
        'file.replace:after' => function ($newFile, $oldFile) {
            $ext = strtolower($newFile->extension());
            $page = $newFile->parent();
            if ($page && $page->template()->name() === 'project') {
                if ($ext === 'json') {
                    detectAndSaveHotspots($page, $newFile->root(), 'json');
                } elseif ($ext === 'glb') {
                    detectAndSaveHotspots($page, $newFile->root(), 'glb');
                }
            }
        },
    ],
]);


// ── Core: parse JSON + merge into page annotations ────────────────────────

/**
 * Parse a JSON file for hotspots and merge into the page's
 * annotations structure field, preserving any existing descriptions.
 *
 * @param \Kirby\Cms\Page $page
 * @param string|null     $filePath explicit path; if null, auto-detected from page field
 * @return array  { count: int, added: int, skipped: int, hotspots: [] }
 */
function detectAndSaveHotspots($page, $filePath = null, $type = null) {

    // ── find the file ─────────────────────────────────────────────────
    if (!$filePath) {
        $uuid = $page->content()->get('model_hotspots_json')->value();
        if ($uuid) {
            $file = kirby()->file($uuid) ?? $page->file($uuid);
            if ($file) {
                $filePath = $file->root();
                $type = 'json';
            }
        }
        
        // Fallback to searching for the exterior GLB
        if (!$filePath) {
            $glb = $page->files()->filterBy('extension', 'glb')->first();
            if ($glb) {
                $filePath = $glb->root();
                $type = 'glb';
            }
        }
        
        if (!$filePath) {
            return ['status' => 'ok', 'count' => 0, 'added' => 0, 'skipped' => 0,
                    'message' => 'Veuillez d\'abord téléverser un GLB ou un Hotspots JSON.', 'hotspots' => []];
        }
    }

    if (!$type) {
        $type = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    }

    // ── parse hotspots ──────────────────────────────────────
    if ($type === 'glb') {
        $hotspots = parseGlbHotspots($filePath);
    } else {
        $hotspots = parseJsonHotspots($filePath);
    }

    if (empty($hotspots)) {
        return ['status' => 'ok', 'count' => 0, 'added' => 0, 'skipped' => 0,
                'message' => 'Le fichier JSON semble vide ou mal formaté.', 'hotspots' => []];
    }

    // ── read existing annotations from the page ───────────────────────────
    $existing = [];
    if ($page->annotations()->isNotEmpty()) {
        foreach ($page->annotations()->toStructure() as $ann) {
            $id = $ann->hotspot_id()->value();
            $existing[$id] = [
                'hotspot_id'  => $id,
                'title'       => $ann->title()->value(),
                'camera_mode' => $ann->camera_mode()->value() ?: 'fly',
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
            $existing[$hs['id']]['title'] = $existing[$hs['id']]['title'] ?: $hs['title'];
            $merged[] = $existing[$hs['id']];
            $skipped++;
        } else {
            $merged[] = [
                'hotspot_id'  => $hs['id'],
                'title'       => $hs['title'],
                'camera_mode' => $hs['camera_mode'],
                'description' => '',
            ];
            $added++;
        }
    }

    // ── persist ───────────────────────────────────────────────────────────
    if (!empty($merged)) {
        try {
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
        'message'  => count($merged) . ' hotspot(s) détecté(s) (' . $added . ' nouveau(x), ' . $skipped . ' existant(s) conservé(s))',
    ];
}

/**
 * Parse arbitrary JSON exported by Blender addons and extract hotspot entries.
 *
 * @param string $jsonPath absolute path to the .json file
 * @return array  array of ['id' => string, 'title' => string, 'camera_mode' => string]
 */
function parseJsonHotspots($jsonPath) {
    if (!file_exists($jsonPath) || !is_readable($jsonPath)) return [];

    $jsonStr = file_get_contents($jsonPath);
    if (!$jsonStr) return [];

    $data = json_decode($jsonStr, true);
    if (!is_array($data)) return [];

    // Extract correct array inside JSON depending on what the addon produced
    $source = [];
    if (isset($data['hotspots']) && is_array($data['hotspots'])) {
        $source = $data['hotspots'];
    } elseif (isset($data['annotations']) && is_array($data['annotations'])) {
        $source = $data['annotations'];
    } else {
        $source = $data; // assume root array
    }

    $hotspots = [];

    foreach ($source as $key => $node) {
        if (!is_array($node)) continue;

        $id         = $node['id']          ?? $node['hotspot_id'] ?? (is_string($key) ? $key : null) ?? $node['name'] ?? null;
        $title      = $node['title']       ?? $node['name']       ?? $id;
        $cameraMode = $node['camera_mode'] ?? $node['mode']       ?? 'fly';

        // Addon specific fallback
        if (empty($id) && isset($node['uuid'])) {
            $id = 'hotspot_' . substr($node['uuid'], 0, 6);
        }

        if (empty($id)) continue;

        $hotspots[] = [
            'id'          => $id,
            'title'       => $title,
            'camera_mode' => $cameraMode,
        ];
    }

    return $hotspots;
}

/**
 * Executes an internal Node script to read GLB binary structure looking for
 * user data / names indicating hotspots.
 */
function parseGlbHotspots($glbPath) {
    if (!file_exists($glbPath)) return [];
    
    $nodeCandidates = [
        'C:\\Program Files\\nodejs\\node.exe',
        'C:\\Program Files (x86)\\nodejs\\node.exe',
    ];
    $node = 'node';
    foreach ($nodeCandidates as $c) {
        if (file_exists($c)) { $node = $c; break; }
    }
    
    $script = __DIR__ . '/extract-glb.js';
    $cmd = sprintf('"%s" %s %s 2>&1', $node, escapeshellarg($script), escapeshellarg($glbPath));
    
    $output = []; $code = 0;
    exec($cmd, $output, $code);
    
    if ($code !== 0) return [];
    
    $json = implode("", $output);
    $data = json_decode($json, true);
    
    return is_array($data) ? $data : [];
}
