<?php

/**
 * GoHéritage PanoViewer plugin.
 *
 * Self-contained Matterport-style panorama + dollhouse viewer. The plugin
 * ships its own ES-module package (assets/panoviewer.js entry point,
 * assets/panoviewer/* internals) plus a stylesheet and a bridge module
 * that boots the viewer from data attributes on a #pano-viewer element.
 *
 * Asset URLs (auto-published by Kirby to media/plugins/goheritage/panoviewer/):
 *   - panoviewer.css
 *   - panoviewer.js
 *   - goheritage-bridge.js
 *
 * Templates / snippets reference assets via the `panoviewer.asset()` helper
 * exposed below so the path stays in one place.
 */

Kirby::plugin('goheritage/panoviewer', [
    // No snippets / blueprints — JS package only. Add fields/snippets here
    // if the integration grows (e.g. project-blueprint pano fields).
]);

if (!function_exists('panoviewerAsset')) {
    /**
     * Resolve a panoviewer plugin asset URL.
     *
     * Kirby auto-publishes plugin assets to media/plugins/.../{hash}/ only
     * on the first PHP-routed request for that file. Local Herd / Valet /
     * nginx setups serve /media/ directly, so Kirby never sees the request
     * and the hash dir stays empty → 404 HTML → "blocked because of a
     * disallowed MIME type" in the browser.
     *
     * Workaround: copy the source file into the published hash dir on each
     * call (cheap; no-op when already present + mtime matches). Returns the
     * canonical media URL Kirby would have generated.
     *
     * @param string $path  e.g. 'panoviewer.js', 'panoviewer.css', 'goheritage-bridge.js'
     * @return string
     */
    function panoviewerAsset(string $path): string
    {
        $plugin = kirby()->plugin('goheritage/panoviewer');
        if (!$plugin) {
            return url('site/plugins/panoviewer/assets/' . ltrim($path, '/'));
        }

        $asset = $plugin->asset($path);
        if (!$asset) {
            return url('site/plugins/panoviewer/assets/' . ltrim($path, '/'));
        }

        // Mirror the source file (and the rest of the assets folder when
        // first asked) into the media hash dir so a direct GET succeeds.
        $src      = $asset->root();
        $destDir  = $plugin->mediaRoot();
        $destFile = $destDir . '/' . $path;
        if (is_file($src) && (!is_file($destFile) || filemtime($src) > filemtime($destFile))) {
            panoviewerMirrorTree(dirname($src), $destDir, dirname($destFile));
        }

        return $asset->url();
    }

    /**
     * Recursively copy any source files newer than their destination
     * counterparts (or missing) into the matching destination tree.
     */
    function panoviewerMirrorTree(string $srcRoot, string $mediaRoot, string $destDir): void
    {
        // First call ensures the whole assets/ folder is mirrored, not just
        // the file we were asked about — sibling internals (panoviewer/*.js)
        // are pulled in by the entry module via relative imports.
        if (!is_dir($mediaRoot)) {
            @mkdir($mediaRoot, 0755, true);
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $entry) {
            $rel  = substr($entry->getPathname(), strlen($srcRoot) + 1);
            $dest = $mediaRoot . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            if ($entry->isDir()) {
                if (!is_dir($dest)) @mkdir($dest, 0755, true);
            } else {
                if (!is_file($dest) || filemtime($entry->getPathname()) > filemtime($dest)) {
                    @copy($entry->getPathname(), $dest);
                }
            }
        }
    }
}
