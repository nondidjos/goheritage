<?php

/**
 * GoHéritage PanoViewer plugin.
 *
 * Self-contained Matterport-style panorama + dollhouse viewer. The plugin
 * ships its own ES-module package (assets/panoviewer.js entry point,
 * assets/panoviewer/* internals) plus a stylesheet and a bridge module
 * that boots the viewer from data attributes on a #pano-viewer element.
 *
 * Asset URLs (auto-published by Kirby to media/plugins/goheritage/panoviewer/{hash}/):
 *   - panoviewer.css
 *   - panoviewer.js
 *   - goheritage-bridge.js
 *
 * Templates / snippets reference assets via the `panoviewerAsset()` helper
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
     * Workaround: derive the versioned hash dir from `$asset->mediaRoot()`,
     * mirror the entire assets/ tree into it on first call, then return the
     * canonical media URL Kirby would have generated. Idempotent + cheap.
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

        // $asset->mediaRoot() resolves to the FILE path inside the versioned
        // hash dir, e.g. media/plugins/goheritage/panoviewer/{hash}/panoviewer.js.
        // The dir for THIS file → its parent. The hash root for the whole
        // assets folder → climb until we hit the level that mirrors $srcRoot.
        $destFile = $asset->mediaRoot();
        $srcFile  = $asset->root();
        $srcRoot  = dirname($srcFile, substr_count($path, '/') + 1);
        $hashRoot = dirname($destFile, substr_count($path, '/') + 1);

        if (is_file($srcFile) && (!is_file($destFile) || filemtime($srcFile) > filemtime($destFile))) {
            panoviewerMirrorTree($srcRoot, $hashRoot);
        }

        return $asset->url();
    }

    /**
     * Recursively copy any source files newer than their destination
     * counterparts (or missing) into the matching destination tree.
     */
    function panoviewerMirrorTree(string $srcRoot, string $destRoot): void
    {
        if (!is_dir($destRoot)) {
            @mkdir($destRoot, 0755, true);
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $entry) {
            $rel  = substr($entry->getPathname(), strlen($srcRoot) + 1);
            $dest = $destRoot . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
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
