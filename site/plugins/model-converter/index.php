<?php

/**
 * model-converter plugin
 * - converts uploaded .obj files to draco-compressed .glb
 * - compresses textures via sharp-cli
 * - provides a custom API route for overwrite-safe file uploads
 * - registers the `upload-overwrite` panel field type
 */

use Kirby\Cms\App as Kirby;
use Kirby\Http\Response;
use Kirby\Filesystem\F;

// Register 3D file extensions as document types so Kirby can categorize them
F::$types['document'][] = 'obj';
F::$types['document'][] = 'mtl';
F::$types['document'][] = 'glb';
F::$types['document'][] = 'gltf';

// JSON is "code" by default in Kirby — move it to document so uploads aren't rejected
if (isset(F::$types['code'])) {
    F::$types['code'] = array_values(array_diff(F::$types['code'], ['json']));
}
F::$types['document'][] = 'json';

Kirby::plugin('goheritage/model-converter', [

    // ── Page method: merged annotation list for blueprint queries ─────────
    'pageMethods' => [
        'allAnnotations' => function () {
            return $this->annotations()->toStructure()
                ->add($this->annotations_interior()->toStructure());
        },
    ],

    // ── Pages collection method: sorted unique tags for select options ────
    // Usage in blueprints: query: site.index.sortedUniqueTags()
    'pagesMethods' => [
        'sortedUniqueTags' => function () {
            $tags = [];
            foreach ($this as $page) {
                foreach (explode(',', (string)$page->tags()) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') $tags[$tag] = $tag;
                }
            }
            sort($tags, SORT_STRING | SORT_FLAG_CASE);
            return array_values($tags);
        },
    ],

    // ── Panel field ──────────────────────────────────────────────────────────
    'fields' => [
        'upload-overwrite' => [
            'computed' => [
                'pageId' => function () {
                    return $this->model()->id();
                },
                'fieldName' => function () {
                    return $this->name();
                },
                'files' => function () {
                    $page      = $this->model();
                    $canonical = goheritageCanonicalBase($this->name());
                    if (!$canonical) return [];

                    // Find any file whose name (without extension) matches the canonical base
                    $matches = $page->files()->filter(
                        fn($f) => pathinfo($f->filename(), PATHINFO_FILENAME) === $canonical
                    );

                    return $matches->values(fn($f) => [
                        'filename' => $f->filename(),
                        'url'      => $f->url(),
                        'id'       => $f->id(),
                        'size'     => $f->niceSize(),
                        'width'    => $f->type() === 'image' ? ($f->dimensions()->width()  ?? null) : null,
                        'height'   => $f->type() === 'image' ? ($f->dimensions()->height() ?? null) : null,
                    ]);
                },
            ],
        ],
        'accordion-trigger' => [],
        'location-search'   => [
            'computed' => [
                'pageId' => function () { return $this->model()->id(); },
            ],
        ],
        'page-files-list'   => [
            'computed' => [
                'pageId' => function () {
                    return $this->model()->id();
                },
                'rows' => function () {
                    $rows = [];
                    foreach ($this->model()->files()->sortBy('filename', 'asc') as $f) {
                        $rows[] = [
                            'filename' => $f->filename(),
                            'url'      => $f->url(),
                            'size'     => $f->niceSize(),
                        ];
                    }
                    return $rows;
                },
            ],
        ],
    ],

    // ── Custom API routes ─────────────────────────────────────────────────────
    'api' => [
        'routes' => [
            [
                'pattern' => 'goheritage/delete-file',
                'method'  => 'DELETE',
                'auth'    => false,
                'action'  => function () {
                    $kirby   = kirby();
                    $request = $kirby->request();

                    if (!$kirby->user()) {
                        return Response::json(['error' => 'Unauthorized'], 401);
                    }

                    $pageId   = $request->get('pageId');
                    $filename = $request->get('filename');

                    if (!$pageId || !$filename) {
                        return Response::json(['error' => 'pageId and filename required'], 400);
                    }

                    $page = $kirby->page($pageId);
                    if (!$page) {
                        return Response::json(['error' => 'Page not found: ' . $pageId], 404);
                    }

                    $file = $page->file(basename($filename));
                    if (!$file) {
                        return Response::json(['error' => 'File not found: ' . $filename], 404);
                    }

                    try {
                        $kirby->impersonate('kirby');
                        $file->delete();
                        return Response::json(['status' => 'deleted', 'filename' => $filename]);
                    } catch (\Throwable $e) {
                        return Response::json(['error' => $e->getMessage()], 500);
                    }
                },
            ],
            [
                'pattern' => 'goheritage/compress-file',
                'method'  => 'POST',
                'auth'    => false,
                'action'  => function () {
                    $kirby   = kirby();
                    $request = $kirby->request();

                    if (!$kirby->user()) {
                        return Response::json(['error' => 'Unauthorized'], 401);
                    }

                    $pageId   = $request->get('pageId');
                    $filename = $request->get('filename');

                    if (!$pageId || !$filename) {
                        return Response::json(['error' => 'pageId and filename required'], 400);
                    }

                    $page = $kirby->page($pageId);
                    if (!$page) {
                        return Response::json(['error' => 'Page not found'], 404);
                    }

                    $file = $page->file(basename($filename));
                    if (!$file) {
                        return Response::json(['error' => 'File not found'], 404);
                    }

                    $size    = max(256, min(8192, (int)($request->get('size',    4096))));
                    $quality = max(10,  min(100,  (int)($request->get('quality', 85))));

                    // Kill any orphaned `convert` process left over from a previous
                    // PHP timeout (the child keeps running after PHP is killed).
                    @shell_exec('pkill -f "convert.*texture" 2>/dev/null');

                    // Serialize all heavy jobs — the server only has 512 MB RAM.
                    // A second request while one is running returns 503 immediately.
                    $lockFile   = sys_get_temp_dir() . '/goheritage-heavy.lock';
                    $lockHandle = @fopen($lockFile, 'w');
                    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
                        if ($lockHandle) @fclose($lockHandle);
                        return Response::json(['error' => 'Un traitement est déjà en cours. Veuillez patienter.'], 503);
                    }

                    try {
                        set_time_limit(600);
                        $kirby->impersonate('kirby');
                        compressTexture($file, $size, $quality);
                        $page            = kirby()->page($pageId);
                        $base            = pathinfo($filename, PATHINFO_FILENAME);
                        $webpFilename    = $base . '.webp';
                        $previewFilename = $base . '-preview.jpg';
                        $newFile         = $page->file($webpFilename);
                        $previewFile     = $page->file($previewFilename);
                        if ($newFile) {
                            return Response::json([
                                'status'     => 'ok',
                                'filename'   => $newFile->filename(),
                                'size'       => $newFile->niceSize(),
                                'url'        => $newFile->url() . '?t=' . time(),
                                'previewUrl' => $previewFile ? $previewFile->url() . '?t=' . time() : null,
                            ]);
                        }
                        return Response::json(['status' => 'ok']);
                    } catch (\Throwable $e) {
                        return Response::json(['error' => $e->getMessage()], 500);
                    } finally {
                        flock($lockHandle, LOCK_UN);
                        @fclose($lockHandle);
                    }
                },
            ],
            [
                'pattern' => 'goheritage/convert-obj',
                'method'  => 'POST',
                'auth'    => false,
                'action'  => function () {
                    $kirby   = kirby();
                    $request = $kirby->request();

                    if (!$kirby->user()) {
                        return Response::json(['error' => 'Unauthorized'], 401);
                    }

                    $pageId   = $request->get('pageId');
                    $filename = $request->get('filename');

                    if (!$pageId || !$filename) {
                        return Response::json(['error' => 'pageId and filename required'], 400);
                    }

                    $page = $kirby->page($pageId);
                    if (!$page) {
                        return Response::json(['error' => 'Page not found'], 404);
                    }

                    $file = $page->file(basename($filename));
                    if (!$file) {
                        return Response::json(['error' => 'File not found'], 404);
                    }

                    if (strtolower($file->extension()) !== 'obj') {
                        return Response::json(['error' => 'File must be an .obj'], 400);
                    }

                    // Shared lock with compress-file — only one heavy job at a time.
                    $lockFile   = sys_get_temp_dir() . '/goheritage-heavy.lock';
                    $lockHandle = @fopen($lockFile, 'w');
                    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
                        if ($lockHandle) @fclose($lockHandle);
                        return Response::json(['error' => 'Un traitement est déjà en cours. Veuillez patienter.'], 503);
                    }

                    try {
                        set_time_limit(3600);
                        $kirby->impersonate('kirby');
                        convertObjToGlb($file);

                        $page        = $kirby->page($pageId);
                        $basename    = pathinfo($filename, PATHINFO_FILENAME);
                        $glbFile     = $page->file($basename . '.glb');

                        if ($glbFile) {
                            return Response::json([
                                'status'   => 'ok',
                                'filename' => $glbFile->filename(),
                                'size'     => $glbFile->niceSize(),
                                'url'      => $glbFile->url(),
                            ]);
                        }
                        return Response::json(['status' => 'ok']);
                    } catch (\Throwable $e) {
                        return Response::json(['error' => $e->getMessage()], 500);
                    } finally {
                        flock($lockHandle, LOCK_UN);
                        @fclose($lockHandle);
                    }
                },
            ],
            [
                'pattern' => 'goheritage/geocode',
                'method'  => 'GET',
                'auth'    => false,
                'action'  => function () {
                    $kirby = kirby();
                    if (!$kirby->user()) {
                        return Response::json(['error' => 'Unauthorized'], 401);
                    }
                    $q = trim($kirby->request()->get('q', ''));
                    if (!$q) {
                        return Response::json(['features' => []]);
                    }
                    $key = $kirby->option('maptiler.key');
                    if (!$key) {
                        return Response::json(['error' => 'maptiler.key not configured'], 500);
                    }
                    $url = 'https://api.maptiler.com/geocoding/' . urlencode($q) . '.json?key=' . urlencode($key) . '&limit=6&language=fr';
                    $json = @file_get_contents($url);
                    if ($json === false) {
                        return Response::json(['error' => 'Geocoding request failed'], 502);
                    }
                    return Response::json(json_decode($json, true));
                },
            ],
            [
                'pattern' => 'goheritage/upload-overwrite',
                'method'  => 'POST',
                'auth'    => false,
                'action'  => function () {
                    // Temporarily raise limits just for this heavy API route
                    ini_set('memory_limit', '1G');
                    set_time_limit(3600);
                    
                    $kirby   = kirby();
                    $request = $kirby->request();

                    // Require a logged-in panel user (session auth)
                    if (!$kirby->user()) {
                        return Response::json(['error' => 'Unauthorized'], 401);
                    }

                    $pageId    = $request->get('pageId');
                    $template  = $request->get('template', 'default');
                    $fieldName = $request->get('fieldName', '');

                    if (!$pageId) {
                        return Response::json(['error' => 'pageId required'], 400);
                    }

                    $page = $kirby->page($pageId);
                    if (!$page) {
                        return Response::json(['error' => 'Page not found: ' . $pageId], 404);
                    }

                    // Grab the uploaded file from $_FILES
                    $uploaded = $_FILES['file'] ?? null;
                    if (!$uploaded || $uploaded['error'] !== UPLOAD_ERR_OK) {
                        $code = $uploaded['error'] ?? -1;
                        return Response::json(['error' => 'Upload error code: ' . $code], 400);
                    }

                    $originalFilename = basename($uploaded['name']);
                    $ext              = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

                    // If the field maps to a canonical name, use it (e.g. exterior.obj)
                    $canonicalBase = goheritageCanonicalBase($fieldName);
                    $filename      = $canonicalBase ? $canonicalBase . '.' . $ext : $originalFilename;

                    // PHP's tmp file has no extension — move to a named temp
                    // file so Kirby's extension validator sees the right type.
                    // We use move instead of copy to save disk space for huge models.
                    $tmpPath = sys_get_temp_dir() . '/' . uniqid('goheritage_') . '_' . $filename;
                    if (!@move_uploaded_file($uploaded['tmp_name'], $tmpPath)) {
                        return Response::json(['error' => 'Failed to stage upload. Ensure the server has enough free disk space.'], 500);
                    }

                    try {
                        $kirby->impersonate('kirby');

                        // Re-fetch page after impersonation — pre-impersonate object is immutable
                        $page     = $kirby->page($pageId);
                        $existing = $page->file($filename);

                        if ($existing) {
                            // Overwrite in-place — bypasses FileRules::notExistingFile()
                            $newFile = $existing->replace($tmpPath);
                        } else {
                            $newFile = $page->createFile([
                                'source'   => $tmpPath,
                                'filename' => $filename,
                                'template' => $template,
                            ]);
                        }

                        // Store the file UUID back into the page content field so that
                        // $page->model_glb()->toFile() (etc.) resolves correctly.
                        // Re-fetch page again — createFile/replace returns a new page version
                        if ($fieldName) {
                            $kirby->page($pageId)->update([$fieldName => $newFile->uuid()]);
                        }

                        return Response::json([
                            'status'   => $existing ? 'replaced' : 'created',
                            'filename' => $newFile->filename(),
                            'url'      => $newFile->url(),
                            'id'       => $newFile->uuid(),
                        ]);

                    } catch (\Throwable $e) {
                        file_put_contents(__DIR__ . '/upload-error.log', $e->getMessage() . "\n" . $e->getTraceAsString());
                        return Response::json(['error' => $e->getMessage()], 500);
                    } finally {
                        @unlink($tmpPath);
                    }
                },
            ],
        ],
    ],

    // ── File hooks ────────────────────────────────────────────────────────────
    // OBJ → GLB conversion is triggered manually via the "→ GLB" button in the panel.
    'hooks' => [],
]);

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Resolve the node binary.  Checks known platform paths before falling back
 * to a bare "node" (works when /usr/bin is in PHP-FPM's PATH, which it is on
 * the Bitnami/NodeSource stack).
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

/**
 * Append a timestamped line to site/logs/model-converter.log.
 * Silently no-ops if the directory isn't writable.
 */
function goheritageLog(string $msg): void {
    $logDir  = __DIR__ . '/../../logs';
    $logFile = $logDir . '/model-converter.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Maps a blueprint field name to the canonical filename base used on disk.
 * Returns null for fields that should keep the original upload name.
 */
function goheritageCanonicalBase($fieldName) {
    static $map = [
        'model_obj'                    => 'exterior',
        'model_obj_interior'           => 'interior',
        'model_texture'                => 'exterior-texture',
        'model_texture_interior'       => 'interior-texture',
        'model_hotspots_json'          => 'hotspots-exterior',
        'model_hotspots_json_interior' => 'hotspots-interior',
    ];
    return $map[$fieldName] ?? null;
}

/**
 * Convert an OBJ file to Draco-compressed GLB using node CLI tools.
 * The resulting GLB replaces (or creates) a same-named .glb file on the page.
 */
function convertObjToGlb($file) {
    $node          = goheritageNodeBin();
    $obj2gltf      = realpath(__DIR__ . '/../../../node_modules/obj2gltf/bin/obj2gltf.js');
    $gltfTransform = realpath(__DIR__ . '/../../../node_modules/@gltf-transform/cli/bin/cli.js');

    $objPath  = $file->root();
    $dir      = dirname($objPath);
    $basename = pathinfo($objPath, PATHINFO_FILENAME);
    // Use two distinct temp names so neither conflicts with the target filename.
    // $finalGlb MUST differ from "$basename.glb" — Kirby's createFile/replace
    // copies from source to target; if source === target it fails with a PHP
    // "Call to a member function template() on null" fatal error.
    $tmpGlb   = $dir . '/' . $basename . '-step1.glb';
    $finalGlb = $dir . '/' . $basename . '-step2.glb';

    goheritageLog("convertObjToGlb START  node=$node  obj=$objPath");
    goheritageLog("  obj2gltf=" . ($obj2gltf ?: 'NOT FOUND'));
    goheritageLog("  gltf-transform=" . ($gltfTransform ?: 'NOT FOUND'));

    if (!$obj2gltf)      throw new \Exception('obj2gltf not found — run npm install on the server.');
    if (!$gltfTransform) throw new \Exception('@gltf-transform/cli not found — run npm install on the server.');

    try {
        // step 1: obj → glb
        $cmd1 = sprintf('"%s" --max-old-space-size=256 "%s" -i %s -o %s --binary --unlit 2>&1',
            $node, $obj2gltf, escapeshellarg($objPath), escapeshellarg($tmpGlb));
        goheritageLog("  cmd1: $cmd1");
        $output1 = []; $code1 = 0;
        exec($cmd1, $output1, $code1);
        goheritageLog("  exit=$code1  " . implode(' | ', $output1));

        if ($code1 !== 0 || !file_exists($tmpGlb)) {
            $msg = "obj2gltf failed (exit $code1): " . implode("\n", $output1);
            goheritageLog("ERROR: $msg");
            throw new \Exception($msg);
        }

        // step 2: draco compression
        $cmd2 = sprintf('"%s" --max-old-space-size=256 "%s" draco %s %s 2>&1',
            $node, $gltfTransform, escapeshellarg($tmpGlb), escapeshellarg($finalGlb));
        goheritageLog("  cmd2: $cmd2");
        $output2 = []; $code2 = 0;
        exec($cmd2, $output2, $code2);
        goheritageLog("  exit=$code2  " . implode(' | ', $output2));

        if ($code2 !== 0 || !file_exists($finalGlb)) {
            $msg = "gltf-transform draco failed (exit $code2): " . implode("\n", $output2);
            goheritageLog("ERROR: $msg");
            throw new \Exception($msg);
        }
    } finally {
        if (file_exists($tmpGlb)) @unlink($tmpGlb);
        if (file_exists($finalGlb)) @unlink($finalGlb);
    }

    try {
        $pageId = $file->parent()?->id();
        if ($pageId) {
            kirby()->impersonate('kirby');
            $page        = kirby()->page($pageId);
            $glbFilename = $basename . '.glb';
            $existing    = $page->file($glbFilename);
            if ($existing) {
                $existing->replace($finalGlb);
            } else {
                $page->createFile([
                    'source'   => $finalGlb,
                    'filename' => $glbFilename,
                    'template' => 'default',
                ]);
            }
        }
        goheritageLog("convertObjToGlb OK");
    } catch (\Throwable $e) {
        // Catches both \Exception and PHP \Error (e.g. method-on-null fatals).
        // If Kirby registration fails, copy the file manually so the viewer
        // can still find it via $page->file('exterior.glb').
        goheritageLog("ERROR registering GLB (fallback copy): " . $e->getMessage());
        error_log('[model-converter] glb registration: ' . $e->getMessage());
        $targetPath = $dir . '/' . $basename . '.glb';
        if (!file_exists($targetPath)) {
            @copy($finalGlb, $targetPath);
        }
    }
}

/**
 * Convert a large PNG texture to an optimised JPEG using compress-texture.js.
 */
function compressTexture($file, $size = 4096, $quality = 85) {
    $node     = goheritageNodeBin();
    $script   = __DIR__ . '/compress-texture.js';
    $srcPath  = $file->root();
    $dir      = dirname($srcPath);
    $basename = pathinfo($srcPath, PATHINFO_FILENAME);
    $tmpPath  = $dir . '/' . $basename . '-tmp.webp';

    goheritageLog("compressTexture START  node=$node  src=$srcPath  size=$size  quality=$quality");

    try {
        $cmd = sprintf('"%s" --max-old-space-size=256 %s %s %s --size=%d --quality=%d 2>&1',
            $node,
            escapeshellarg($script),
            escapeshellarg($srcPath),
            escapeshellarg($tmpPath),
            $size, $quality);
        goheritageLog("  cmd: $cmd");
        $output = []; $code = 0;
        exec($cmd, $output, $code);
        goheritageLog("  exit=$code  " . implode(' | ', $output));

        if ($code !== 0 || !file_exists($tmpPath)) {
            $msg = "compress-texture.js failed (exit $code): " . implode("\n", $output);
            goheritageLog("ERROR: $msg");
            throw new \Exception($msg);
        }

        $pageId = $file->parent()?->id();
        if ($pageId) {
            kirby()->impersonate('kirby');
            $page         = kirby()->page($pageId);
            $webpFilename = $basename . '.webp';
            $webpRoot     = $dir . '/' . $webpFilename;
            $existing     = $page->file($webpFilename);
            if ($existing) {
                $existing->replace($tmpPath);
            } elseif (file_exists($webpRoot)) {
                copy($tmpPath, $webpRoot);
            } else {
                $page->createFile([
                    'source'   => $tmpPath,
                    'filename' => $webpFilename,
                    'template' => 'image',
                ]);
            }

            // Save companion 1024 px preview generated by Stage 3 of the script.
            $tmpPreviewPath  = preg_replace('/\.webp$/', '-preview.jpg', $tmpPath);
            if (file_exists($tmpPreviewPath)) {
                $previewFilename = $basename . '-preview.jpg';
                $previewRoot     = $dir . '/' . $previewFilename;
                $existingPreview = $page->file($previewFilename);
                if ($existingPreview) {
                    $existingPreview->replace($tmpPreviewPath);
                } elseif (file_exists($previewRoot)) {
                    copy($tmpPreviewPath, $previewRoot);
                } else {
                    $page->createFile([
                        'source'   => $tmpPreviewPath,
                        'filename' => $previewFilename,
                        'template' => 'image',
                    ]);
                }
            }
        }
        goheritageLog("compressTexture OK");
    } catch (\Exception $e) {
        goheritageLog("EXCEPTION: " . $e->getMessage());
        throw new \Exception('[model-converter] compressTexture: ' . $e->getMessage());
    } finally {
        if (file_exists($tmpPath)) @unlink($tmpPath);
        $tmpPreviewPath = preg_replace('/\.webp$/', '-preview.jpg', $tmpPath);
        if (file_exists($tmpPreviewPath)) @unlink($tmpPreviewPath);
    }
}

/**
 * Compress textures embedded inside a GLB file using gltf-transform.
 * Resizes all embedded textures to a max of 4096px and converts to WebP.
 * Modifies the file in-place (tmp → replace).
 */
function compressGlbTextures($file) {
    $node          = goheritageNodeBin();
    $gltfTransform = realpath(__DIR__ . '/../../../node_modules/@gltf-transform/cli/bin/cli.js');
    goheritageLog("compressGlbTextures START  glb=" . $file->root());
    $glbPath = $file->root();
    $tmpPath = $glbPath . '.compressing.glb';

    // Step 1: resize embedded textures to max 4096 px
    $cmd1 = sprintf(
        '"%s" --max-old-space-size=256 "%s" resize %s %s --width 4096 --height 4096 2>&1',
        $node,
        $gltfTransform,
        escapeshellarg($glbPath),
        escapeshellarg($tmpPath)
    );
    $out1 = []; $code1 = 0;
    exec($cmd1, $out1, $code1);

    if ($code1 !== 0 || !file_exists($tmpPath)) {
        error_log('[model-converter] glb texture resize failed: ' . implode("\n", $out1));
        return;
    }

    // Step 2: convert textures to WebP at quality 80
    $tmpPath2 = $glbPath . '.webp.glb';
    $cmd2 = sprintf(
        '"%s" --max-old-space-size=256 "%s" webp %s %s --quality 80 2>&1',
        $node,
        $gltfTransform,
        escapeshellarg($tmpPath),
        escapeshellarg($tmpPath2)
    );
    $out2 = []; $code2 = 0;
    exec($cmd2, $out2, $code2);

    @unlink($tmpPath);

    if ($code2 !== 0 || !file_exists($tmpPath2)) {
        error_log('[model-converter] glb webp compression failed: ' . implode("\n", $out2));
        return;
    }

    // Replace the original GLB with the compressed version
    try {
        kirby()->impersonate('kirby');
        $file->replace($tmpPath2);
    } catch (\Exception $e) {
        error_log('[model-converter] glb replace after compression failed: ' . $e->getMessage());
    } finally {
        @unlink($tmpPath2);
    }
}
