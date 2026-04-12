<?php

/**
 * GoHéritage — Hotspot Detector plugin
 *
 * Reads hotspot JSON files and populates two separate annotation structure
 * fields on the project page: `annotations` (exterior) and
 * `annotations_interior` (interior).
 *
 * Runs automatically whenever a JSON file is uploaded to a project page.
 */

use Kirby\Cms\App as Kirby;
use Kirby\Data\Yaml;

Kirby::plugin('goheritage/hotspot-detector', [

    // ── Hooks: auto-detect when a JSON is uploaded ────────────────────────
    'hooks' => [
        'file.create:after' => function ($file) {
            $page = $file->parent();
            if ($page && $page->template()->name() === 'project'
                      && strtolower($file->extension()) === 'json') {
                detectAndSaveHotspots($page);
            }
        },
        'file.replace:after' => function ($newFile, $oldFile) {
            $page = $newFile->parent();
            if ($page && $page->template()->name() === 'project'
                      && strtolower($newFile->extension()) === 'json') {
                detectAndSaveHotspots($page);
            }
        },
    ],
]);


// ── Core ──────────────────────────────────────────────────────────────────────

/**
 * Read both JSON fields, parse exterior/interior hotspots separately,
 * merge into `annotations` and `annotations_interior`, persist.
 */
function detectAndSaveHotspots($page) {

    $exteriorHotspots = [];
    $interiorHotspots = [];

    // Exterior JSON → exterior section
    $extFile = resolveFileField($page, 'model_hotspots_json');
    if ($extFile) {
        $exteriorHotspots = parseJsonHotspotsByScope($extFile->root(), 'exterior');
    }

    // Interior JSON → interior section
    $intFile = resolveFileField($page, 'model_hotspots_json_interior');
    if ($intFile) {
        $interiorHotspots = parseJsonHotspotsByScope($intFile->root(), 'interior');
    }

    // GLB fallback when no JSON at all
    if (empty($exteriorHotspots) && empty($interiorHotspots)) {
        $glb = $page->files()->filterBy('extension', 'glb')->first();
        if ($glb) {
            $exteriorHotspots = parseGlbHotspots($glb->root());
        }
    }

    if (empty($exteriorHotspots) && empty($interiorHotspots)) {
        return [
            'status'  => 'ok', 'count' => 0, 'added' => 0, 'skipped' => 0,
            'message' => 'Veuillez d\'abord téléverser un GLB ou un Hotspots JSON.',
            'hotspots' => [],
        ];
    }

    [$extMerged, $extAdded, $extSkipped] = mergeAnnotations(
        $page, $exteriorHotspots, 'annotations'
    );
    [$intMerged, $intAdded, $intSkipped] = mergeAnnotations(
        $page, $interiorHotspots, 'annotations_interior'
    );

    $update = [];
    if (!empty($exteriorHotspots)) $update['annotations']          = Yaml::encode($extMerged);
    if (!empty($interiorHotspots)) $update['annotations_interior'] = Yaml::encode($intMerged);

    if (!empty($update)) {
        try {
            kirby()->impersonate('kirby');
            $page->update($update);
        } catch (\Throwable $e) {
            error_log('[hotspot-detector] update failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage(),
                    'count' => 0, 'added' => 0, 'skipped' => 0, 'hotspots' => []];
        }
    }

    $totalCount   = count($extMerged)   + count($intMerged);
    $totalAdded   = $extAdded   + $intAdded;
    $totalSkipped = $extSkipped + $intSkipped;
    $allHotspots  = array_merge($exteriorHotspots, $interiorHotspots);

    return [
        'status'   => 'ok',
        'count'    => $totalCount,
        'added'    => $totalAdded,
        'skipped'  => $totalSkipped,
        'hotspots' => $allHotspots,
        'message'  => $totalCount . ' hotspot(s) détecté(s) ('
                      . $totalAdded . ' nouveau(x), '
                      . $totalSkipped . ' existant(s) conservé(s))',
    ];
}

/**
 * Resolve a file UUID stored in a page content field to a Kirby File object.
 */
function resolveFileField($page, $fieldName) {
    $uuid = $page->content()->get($fieldName)->value();
    if (!$uuid) return null;
    return kirby()->file($uuid) ?? $page->file($uuid) ?? null;
}

/**
 * Merge a list of hotspot definitions into an existing annotations field,
 * preserving user-written descriptions and titles.
 *
 * Returns [$merged, $added, $skipped].
 */
function mergeAnnotations($page, array $hotspots, string $field): array {
    $existing = [];
    $raw = $page->content()->get($field);
    if ($raw->isNotEmpty()) {
        foreach ($raw->toStructure() as $ann) {
            $id = $ann->hotspot_id()->value();
            if ($id) {
                $existing[$id] = [
                    'hotspot_id'  => $id,
                    'title'       => $ann->title()->value(),
                    'camera_mode' => $ann->camera_mode()->value() ?: 'fly',
                    'description' => $ann->description()->value(),
                ];
            }
        }
    }

    $merged  = [];
    $added   = 0;
    $skipped = 0;

    foreach ($hotspots as $hs) {
        if (isset($existing[$hs['id']])) {
            // Keep user's title/description; only fill title if blank
            $row = $existing[$hs['id']];
            if (empty($row['title'])) $row['title'] = $hs['title'];
            $merged[] = $row;
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

    return [$merged, $added, $skipped];
}

/**
 * Parse hotspots from a JSON file exported by the GoHéritage Blender addon.
 *
 * $scope: 'exterior' | 'interior'
 *
 * Handles:
 *   - Scoped format: { "exterior": { "hotspots": [...] }, "interior": { ... } }
 *   - Flat format:   { "hotspots": [...] }  or  [ {...}, ... ]
 *
 * For scoped files the requested scope is returned; flat files are returned
 * entirely (field assignment determines the scope).
 */
function parseJsonHotspotsByScope(string $jsonPath, string $scope): array {
    if (!file_exists($jsonPath) || !is_readable($jsonPath)) return [];
    $data = json_decode(file_get_contents($jsonPath), true);
    if (!is_array($data)) return [];

    // Scoped format
    if (isset($data['exterior']) || isset($data['interior'])) {
        $nodes = $data[$scope]['hotspots'] ?? [];
        return extractHotspotNodes($nodes);
    }

    // Flat format: { "hotspots": [...] }
    if (isset($data['hotspots']) && is_array($data['hotspots'])) {
        return extractHotspotNodes($data['hotspots']);
    }

    // Flat format: { "annotations": [...] }
    if (isset($data['annotations']) && is_array($data['annotations'])) {
        return extractHotspotNodes($data['annotations']);
    }

    // Root array
    if (array_is_list($data)) {
        return extractHotspotNodes($data);
    }

    return [];
}

/**
 * Normalise a raw array of hotspot node arrays into
 * [['id' => ..., 'title' => ..., 'camera_mode' => ...], ...]
 */
function extractHotspotNodes(array $nodes): array {
    $out = [];
    foreach ($nodes as $key => $node) {
        if (!is_array($node)) continue;
        $id   = $node['id'] ?? $node['hotspot_id'] ?? (is_string($key) ? $key : null) ?? $node['name'] ?? null;
        if (empty($id) && isset($node['uuid'])) {
            $id = 'hotspot_' . substr($node['uuid'], 0, 6);
        }
        if (empty($id)) continue;
        $out[] = [
            'id'          => $id,
            'title'       => $node['title']       ?? $node['name'] ?? $id,
            'camera_mode' => $node['camera_mode'] ?? $node['mode'] ?? 'fly',
        ];
    }
    return $out;
}

/**
 * Parse hotspot names/positions embedded in a GLB via Node.js helper.
 */
function parseGlbHotspots(string $glbPath): array {
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
    $cmd    = sprintf('"%s" %s %s 2>&1', $node, escapeshellarg($script), escapeshellarg($glbPath));
    $output = []; $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) return [];

    $data = json_decode(implode('', $output), true);
    return is_array($data) ? $data : [];
}
