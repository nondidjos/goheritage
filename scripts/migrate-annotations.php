<?php

/**
 * One-shot migration: merge the legacy `annotations_interior` field into
 * the unified `annotations` field, tagging every row with its `location`
 * (exterior or interior).
 *
 * Background:
 *   - Up to May 2026 each project page had two parallel structure fields:
 *     `annotations` (exterior hotspots) and `annotations_interior`
 *     (interior hotspots). Identical schema, only the field name
 *     distinguished them.
 *   - Readers had to know about both. Editors saw the same UI twice.
 *   - This script collapses them into a single `annotations` structure
 *     where each row carries `location: exterior|interior`.
 *
 * Idempotent:
 *   - Existing exterior rows missing a `location` value are tagged
 *     `exterior` (their implicit scope).
 *   - Existing interior rows are appended with `location: interior`.
 *   - `annotations_interior` is cleared after the lift.
 *   - Running the script a second time finds nothing to do.
 *
 * Safety:
 *   - Dry-run by default. Pass `--commit` to actually write.
 *   - Per-page errors are reported and skipped; the run continues.
 *
 * Usage (from project root):
 *   php scripts/migrate-annotations.php          # dry run, no writes
 *   php scripts/migrate-annotations.php --commit # actually migrate
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$dryRun = !in_array('--commit', $argv, true);

require __DIR__ . '/../kirby/bootstrap.php';
$kirby = new \Kirby\Cms\App([
    'roots' => [
        'index' => realpath(__DIR__ . '/..'),
    ],
]);

// Need admin context so we can call $page->update() through impersonation.
$kirby->impersonate('kirby');

echo "==============================================================\n";
echo "  Annotations migration  (" . ($dryRun ? "DRY RUN" : "COMMITTING") . ")\n";
echo "==============================================================\n\n";

$pages = $kirby->site()->index()->filterBy('intendedTemplate', 'project');
echo "Found {$pages->count()} project page(s).\n\n";

$migrated = 0;
$skipped  = 0;
$errors   = 0;

foreach ($pages as $page) {
    $extField = $page->content()->get('annotations');
    $intField = $page->content()->get('annotations_interior');

    $hasInterior = $intField->isNotEmpty();

    // Read existing exterior rows, retag any missing `location` as exterior.
    // Rows go through goheritageAnnotationRow (goheritage-core, loaded by the
    // Kirby bootstrap above) so the canonical shape stays defined in one place
    // and empty/missing values get the builder's defaults.
    $extRows = [];
    if ($extField->isNotEmpty()) {
        foreach ($extField->toStructure() as $r) {
            $extRows[] = goheritageAnnotationRow([
                'location'    => $r->location()->value(),   // builder defaults '' to exterior
                'hotspot_id'  => $r->hotspot_id()->value(),
                'title'       => $r->title()->value(),
                'camera_mode' => $r->camera_mode()->value(),
                'description' => $r->description()->value(),
            ]);
        }
    }

    // Read interior rows.
    $intRows = [];
    if ($hasInterior) {
        foreach ($intField->toStructure() as $r) {
            $intRows[] = goheritageAnnotationRow([
                'location'    => 'interior',
                'hotspot_id'  => $r->hotspot_id()->value(),
                'title'       => $r->title()->value(),
                'camera_mode' => $r->camera_mode()->value(),
                'description' => $r->description()->value(),
            ]);
        }
    }

    // Are any existing exterior rows actually missing a `location`?
    // (Pre-migration rows have no location; freshly-saved rows do.)
    $extNeedsRetag = false;
    foreach (($extField->isNotEmpty() ? $extField->toStructure() : []) as $r) {
        if ($r->location()->isEmpty()) { $extNeedsRetag = true; break; }
    }

    // Nothing to do?
    if (!$hasInterior && !$extNeedsRetag) {
        $skipped++;
        continue;
    }

    $merged = array_merge($extRows, $intRows);

    $label = $page->id();
    echo "→ {$label}\n";
    echo "    exterior rows: " . count($extRows) . "\n";
    echo "    interior rows: " . count($intRows) . " (will move into annotations)\n";
    echo "    total after  : " . count($merged) . "\n";

    if ($dryRun) {
        echo "    [dry run — not writing]\n\n";
        $migrated++;
        continue;
    }

    try {
        $page->update([
            'annotations'          => \Kirby\Data\Yaml::encode($merged),
            'annotations_interior' => '', // clear the legacy field
        ]);
        echo "    [OK]\n\n";
        $migrated++;
    } catch (\Throwable $e) {
        echo "    [ERROR] " . $e->getMessage() . "\n\n";
        $errors++;
    }
}

echo "==============================================================\n";
echo "  Done.\n";
echo "  Pages migrated: {$migrated}\n";
echo "  Pages skipped : {$skipped} (nothing to do)\n";
echo "  Errors        : {$errors}\n";
echo "==============================================================\n";

if ($dryRun) {
    echo "\nThis was a dry run. Re-run with --commit to apply.\n";
}
