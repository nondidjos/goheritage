<?php

/**
 * GoHéritage — Hotspot Detector plugin
 *
 * Reads hotspot JSON files (exterior + interior) and populates a single
 * `annotations` structure field on the project page, tagging each row
 * with a `location: exterior|interior` value so the viewer knows which
 * model to attach the marker to.
 *
 * Up to May 2026 this plugin wrote to two separate fields, `annotations`
 * and `annotations_interior`. That doubled-up structure was hard to keep
 * in sync and forced every reader to know about both fields. After the
 * merge a single field carries both scopes; `scripts/migrate-annotations.php`
 * lifted existing content into the new shape.
 *
 * Runs automatically on every JSON upload (`file.create:after`) and
 * replacement (`file.replace:after`) for `.json` files attached to a
 * project page.
 */

use Kirby\Cms\App as Kirby;
use Kirby\Data\Yaml;

Kirby::plugin('goheritage/hotspot-detector', [

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


// ── Core ──────────────────────────────────────────────────────────────────

/**
 * Read both JSON fields, parse exterior/interior hotspots separately,
 * merge them into a single `annotations` structure tagged by location,
 * and persist back to the page.
 */
function detectAndSaveHotspots($page) {

    $exteriorHotspots = [];
    $interiorHotspots = [];

    // Exterior JSON → exterior scope
    $extFile = resolveFileField($page, 'model_hotspots_json');
    if ($extFile) {
        $exteriorHotspots = parseJsonHotspotsByScope($extFile->root(), 'exterior');
    }

    // Interior JSON → interior scope
    $intFile = resolveFileField($page, 'model_hotspots_json_interior');
    if ($intFile) {
        $interiorHotspots = parseJsonHotspotsByScope($intFile->root(), 'interior');
    }

    // GLB fallback when no JSON at all (counts as exterior)
    if (empty($exteriorHotspots) && empty($interiorHotspots)) {
        $glb = $page->files()->filterBy('extension', 'glb')->first();
        if ($glb) {
            $exteriorHotspots = parseGlbHotspots($glb->root());
        }
    }

    if (empty($exteriorHotspots) && empty($interiorHotspots)) {
        return [
            'status'  => 'ok', 'count' => 0, 'added' => 0, 'skipped' => 0,
            'message' => 'Veuillez d\'abord ajouter un GLB ou un Hotspots JSON.',
            'hotspots' => [],
        ];
    }

    // Merge into the single `annotations` field. The merger walks the
    // existing rows (which may include rows in either scope) and only
    // touches rows belonging to the scope we're updating, so editing
    // the exterior JSON doesn't wipe interior hotspots and vice versa.
    [$merged, $added, $skipped] = mergeAnnotations(
        $page,
        array_merge(
            tagLocation($exteriorHotspots, 'exterior'),
            tagLocation($interiorHotspots, 'interior'),
        ),
        // Which scopes are we updating right now? Used to decide which
        // existing rows to replace vs. preserve.
        scopesUpdating($exteriorHotspots, $interiorHotspots)
    );

    try {
        kirby()->impersonate('kirby');
        $page->update(['annotations' => Yaml::encode($merged)]);
    } catch (\Throwable $e) {
        error_log('[hotspot-detector] update failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage(),
                'count' => 0, 'added' => 0, 'skipped' => 0, 'hotspots' => []];
    }

    $allHotspots = array_merge($exteriorHotspots, $interiorHotspots);
    return [
        'status'   => 'ok',
        'count'    => count($merged),
        'added'    => $added,
        'skipped'  => $skipped,
        'hotspots' => $allHotspots,
        'message'  => count($merged) . ' hotspot(s) — '
                      . $added   . ' nouveau(x), '
                      . $skipped . ' existant(s) conservé(s)',
    ];
}

/**
 * Tag each hotspot dict with a `location` value, so the merger can
 * group/track them by scope.
 */
function tagLocation(array $hotspots, string $location): array {
    return array_map(fn ($h) => $h + ['location' => $location], $hotspots);
}

/**
 * Which scopes are we updating in this run? Used by the merger to decide
 * which previously-stored rows to keep vs. overwrite.
 */
function scopesUpdating(array $ext, array $int): array {
    $scopes = [];
    if (!empty($ext)) $scopes[] = 'exterior';
    if (!empty($int)) $scopes[] = 'interior';
    return $scopes;
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
 * Merge incoming hotspots into the existing `annotations` field.
 *
 *   $page             — the page being updated
 *   $incoming         — array of ['id', 'title', 'camera_mode', 'location']
 *   $scopesUpdating   — array of location values being refreshed this run.
 *                       Existing rows in OTHER locations are left untouched;
 *                       rows in the updated locations are diffed by id so
 *                       user-edited titles/descriptions survive.
 *
 * Returns [$merged, $added, $skipped] where $merged is the YAML-ready row
 * list ordered as [other-scope rows kept] + [updated scope rows].
 */
function mergeAnnotations($page, array $incoming, array $scopesUpdating): array {
    // Bucket existing rows by id (within updated scopes) and keep a
    // verbatim copy of rows in unchanged scopes for re-emission.
    $existingByScope = ['exterior' => [], 'interior' => []];
    $preserved       = []; // rows we'll re-emit untouched

    $raw = $page->content()->get('annotations');
    if ($raw->isNotEmpty()) {
        foreach ($raw->toStructure() as $ann) {
            $scope = $ann->location()->or('exterior')->value();
            $row = [
                'location'    => $scope,
                'hotspot_id'  => $ann->hotspot_id()->value(),
                'title'       => $ann->title()->value(),
                'camera_mode' => $ann->camera_mode()->or('fly')->value(),
                'description' => $ann->description()->value(),
            ];

            if (in_array($scope, $scopesUpdating, true)) {
                $id = $row['hotspot_id'];
                if ($id) $existingByScope[$scope][$id] = $row;
            } else {
                $preserved[] = $row;
            }
        }
    }

    $merged  = $preserved;
    $added   = 0;
    $skipped = 0;

    foreach ($incoming as $hs) {
        $scope = $hs['location'];
        if (isset($existingByScope[$scope][$hs['id']])) {
            // Keep the user's edits; only backfill an empty title.
            $row = $existingByScope[$scope][$hs['id']];
            if (empty($row['title'])) $row['title'] = $hs['title'];
            $merged[] = $row;
            $skipped++;
        } else {
            $merged[] = [
                'location'    => $scope,
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

    if (isset($data['exterior']) || isset($data['interior'])) {
        $nodes = $data[$scope]['hotspots'] ?? [];
        return extractHotspotNodes($nodes);
    }
    if (isset($data['hotspots']) && is_array($data['hotspots'])) {
        return extractHotspotNodes($data['hotspots']);
    }
    if (isset($data['annotations']) && is_array($data['annotations'])) {
        return extractHotspotNodes($data['annotations']);
    }
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
        $id = $node['id'] ?? $node['hotspot_id'] ?? (is_string($key) ? $key : null) ?? $node['name'] ?? null;
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
