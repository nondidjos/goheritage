<?php

/**
 * goheritage-core — shared infrastructure for the GoHéritage plugins.
 *
 * Hosts the Node shell-out module: one deep interface that every plugin's
 * "shell out to a Node CLI" path goes through, replacing four divergent
 * exec() sites (model-converter ×3, hotspot-detector ×1, plan-viewer ×1)
 * that each re-implemented binary resolution, argument escaping, stderr
 * capture, and the sync-vs-background split.
 *
 * Also hosts the canonical annotation row builder so the hotspot pipeline
 * and its readers share one shape instead of three implicit copies.
 *
 * Kirby loads plugin folders alphabetically (goheritage-core < hotspot-
 * detector < model-converter < plan-viewer), and every caller is a route or
 * hook action that runs at request time — well after all plugin index.php
 * files have registered — so these globals are always defined when called.
 * Each is guarded with function_exists() so a stale copy elsewhere can't
 * fatal on redeclare.
 */

use Kirby\Cms\App as Kirby;

Kirby::plugin('goheritage/core', []);

if (!function_exists('goheritageNodeBin')) {
    /**
     * Resolve the node binary. Checks known platform paths before falling
     * back to a bare "node" (works when /usr/bin is in PHP-FPM's PATH, which
     * it is on the Bitnami/NodeSource stack).
     */
    function goheritageNodeBin(): string {
        static $resolved = null;
        if ($resolved !== null) return $resolved;

        $candidates = [
            '/usr/bin/node',          // Linux — NodeSource / system package
            '/usr/local/bin/node',
            '/usr/bin/nodejs',
            '/opt/homebrew/bin/node', // macOS arm64
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c) && is_executable($c)) {
                return $resolved = $c;
            }
        }
        $which = PHP_OS_FAMILY === 'Windows' ? 'where node 2>nul' : 'which node 2>/dev/null';
        $out   = @shell_exec($which);
        if ($out) {
            $path = trim(explode("\n", $out)[0]);
            if ($path && file_exists($path)) return $resolved = $path;
        }
        return $resolved = 'node';
    }
}

if (!function_exists('goheritageNodeLog')) {
    /**
     * Append a timestamped line to site/logs/<channel>.log. Silently no-ops
     * if the directory isn't writable.
     */
    function goheritageNodeLog(string $channel, string $msg): void {
        $logDir  = __DIR__ . '/../../logs';
        $logFile = $logDir . '/' . $channel . '.log';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('goheritageNodeJob')) {
    /**
     * Run a Node CLI script. One interface for every shell-out.
     *
     *   $script   absolute path to the .js entry point
     *   $args     ordered CLI arguments (flags + paths); each is shell-escaped
     *   $opts     [
     *               'maxOldSpace' => ?int     --max-old-space-size value, or
     *                                         null to omit the flag (default null)
     *               'background'  => bool     fire-and-forget; appends "&" on
     *                                         POSIX. Windows always runs sync
     *                                         (default false)
     *               'logChannel'  => ?string  log the command + result to
     *                                         site/logs/<channel>.log
     *               'logFile'     => ?string  background stdout/stderr redirect
     *                                         target (defaults to a temp file)
     *             ]
     *
     * Returns ['ok' => bool, 'code' => int, 'output' => string[]].
     * Background jobs return ok=true / code=0 / output=[] immediately — they
     * can't be awaited.
     */
    function goheritageNodeJob(string $script, array $args = [], array $opts = []): array {
        $node        = goheritageNodeBin();
        $maxOldSpace = $opts['maxOldSpace'] ?? null;
        $background  = $opts['background'] ?? false;
        $logChannel  = $opts['logChannel'] ?? null;
        $isWindows   = DIRECTORY_SEPARATOR === '\\';

        $parts = [escapeshellarg($node)];
        if ($maxOldSpace !== null) {
            $parts[] = '--max-old-space-size=' . (int) $maxOldSpace;
        }
        $parts[] = escapeshellarg($script);
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        $cmd = implode(' ', $parts);

        // Background only makes sense on POSIX; Windows falls back to sync.
        if ($background && !$isWindows) {
            $logFile = $opts['logFile'] ?? (sys_get_temp_dir() . '/goheritage-node.log');
            $cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
            if ($logChannel) goheritageNodeLog($logChannel, "spawn(bg): $cmd");
            @exec($cmd);
            return ['ok' => true, 'code' => 0, 'output' => []];
        }

        $cmd .= ' 2>&1';
        if ($logChannel) goheritageNodeLog($logChannel, "exec: $cmd");
        $output = []; $code = 0;
        exec($cmd, $output, $code);
        if ($logChannel) goheritageNodeLog($logChannel, "  exit=$code  " . implode(' | ', $output));

        return ['ok' => $code === 0, 'code' => $code, 'output' => $output];
    }
}

if (!function_exists('goheritageAnnotationRow')) {
    /**
     * Canonical annotation row shape — the single definition of what an
     * annotation is on disk. The hotspot pipeline writes these; readers
     * (allAnnotations page method, the project template) consume them.
     * Centralising the shape here is what stops a missing key from silently
     * producing a null-valued row that breaks the 3D viewer.
     *
     *   $values  any subset of the canonical keys
     * Returns a row with every key present and sane defaults applied.
     *
     * location and camera_mode use ?: (not ??) deliberately: Kirby's
     * Field::value() returns '' for a present-but-empty field, and an empty
     * enum value is just as broken for the viewer as a missing one. Callers
     * therefore don't need their own ->or() defaults for these keys.
     */
    function goheritageAnnotationRow(array $values): array {
        return [
            'location'    => ($values['location']    ?? null) ?: 'exterior',
            'hotspot_id'  => ($values['hotspot_id']  ?? null) ?: (($values['id'] ?? null) ?: ''),
            'title'       => $values['title']       ?? '',
            'camera_mode' => ($values['camera_mode'] ?? null) ?: 'fly',
            'description' => $values['description'] ?? '',
        ];
    }
}
