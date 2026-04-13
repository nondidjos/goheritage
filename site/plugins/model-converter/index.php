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

                    try {
                        set_time_limit(300);
                        $kirby->impersonate('kirby');
                        compressTexture($file, $size, $quality);
                        return Response::json(['status' => 'ok']);
                    } catch (\Throwable $e) {
                        return Response::json(['error' => $e->getMessage()], 500);
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

                        // Manually trigger post-upload processing since the
                        // custom route bypasses Kirby's file.create:after hook.
                        // OBJ → GLB conversion runs automatically; texture compression
                        // is triggered manually via the quality picker in the panel.
                        if ($ext === 'obj') {
                            convertObjToGlb($newFile);
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
    'hooks' => [
        // fires after a file has been created (uploaded) in the panel
        'file.create:after' => function ($file) {
            $ext = strtolower($file->extension());
            if ($ext === 'obj') convertObjToGlb($file);
        },

        // fires after a file has been replaced (re-uploaded via panel default)
        'file.replace:after' => function ($newFile, $oldFile) {
            $ext = strtolower($newFile->extension());
            if ($ext === 'obj') convertObjToGlb($newFile);
        },
    ],
]);

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
    // Use full path so PHP's exec() finds npx regardless of web-server PATH
    $candidates = [
        'C:\\Program Files\\nodejs\\npx.cmd',
        'C:\\Program Files (x86)\\nodejs\\npx.cmd',
    ];
    $npx = 'npx';
    foreach ($candidates as $c) {
        if (file_exists($c)) { $npx = $c; break; }
    }

    $objPath  = $file->root();
    $dir      = dirname($objPath);
    $basename = pathinfo($objPath, PATHINFO_FILENAME);
    $tmpGlb   = $dir . '/' . $basename . '-tmp.glb';
    $finalGlb = $dir . '/' . $basename . '.glb';

    try {
        // step 1: obj → glb
        $cmd1 = sprintf(
            '"%s" obj2gltf -i %s -o %s --binary --unlit 2>&1',
            $npx,
            escapeshellarg($objPath),
            escapeshellarg($tmpGlb)
        );
        $output1 = []; $code1 = 0;
        exec($cmd1, $output1, $code1);
    
        if ($code1 !== 0 || !file_exists($tmpGlb)) {
            error_log('[model-converter] obj2gltf failed: ' . implode("\n", $output1));
            return;
        }
    
        // step 2: draco compression
        $cmd2 = sprintf(
            '"%s" gltf-transform draco %s %s 2>&1',
            $npx,
            escapeshellarg($tmpGlb),
            escapeshellarg($finalGlb)
        );
        $output2 = []; $code2 = 0;
        exec($cmd2, $output2, $code2);
    
        if ($code2 !== 0 || !file_exists($finalGlb)) {
            error_log('[model-converter] gltf-transform draco failed: ' . implode("\n", $output2));
            return;
        }
    } finally {
        if (file_exists($tmpGlb)) @unlink($tmpGlb);
    }

    try {
        $page = $file->parent();
        if ($page) {
            kirby()->impersonate('kirby');
            $existing = $page->file($basename . '.glb');
            if ($existing) {
                $existing->replace($finalGlb);
            } else {
                $page->createFile([
                    'source'   => $finalGlb,
                    'filename' => $basename . '.glb',
                    'template' => 'model',
                ]);
            }
        }
    } catch (\Exception $e) {
        error_log('[model-converter] glb registration: ' . $e->getMessage());
    }
}

/**
 * convert a large texture to optimised jpeg using sharp-cli
 */
function compressTexture($file, $size = 4096, $quality = 85) {
    $nodeCandidates = [
        'C:\\Program Files\\nodejs\\node.exe',
        'C:\\Program Files (x86)\\nodejs\\node.exe',
    ];
    $node = 'node'; // fallback: use node from PATH (works on Linux/macOS)
    foreach ($nodeCandidates as $c) {
        if (file_exists($c)) { $node = $c; break; }
    }

    $script   = __DIR__ . '/compress-texture.js';
    $srcPath  = $file->root();
    $dir      = dirname($srcPath);
    $basename = pathinfo($srcPath, PATHINFO_FILENAME);
    $tmpPath  = $dir . '/' . $basename . '-tmp.jpg';

    try {
        $cmd = sprintf('"%s" %s %s %s --size=%d --quality=%d 2>&1', $node, escapeshellarg($script), escapeshellarg($srcPath), escapeshellarg($tmpPath), $size, $quality);
        $output = []; $code = 0;
        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($tmpPath)) {
            throw new \Exception('[model-converter] compress-texture.js failed: ' . implode("\n", $output));
        }

        $page = $file->parent();
        if ($page) {
            kirby()->impersonate('kirby');
            $jpgFilename = $basename . '.jpg';
            $jpgRoot     = $dir . '/' . $jpgFilename;
            $existing    = $page->file($jpgFilename);
            if ($existing) {
                $existing->replace($tmpPath);
            } elseif (file_exists($jpgRoot)) {
                copy($tmpPath, $jpgRoot);
            } else {
                $page->createFile([
                    'source'   => $tmpPath,
                    'filename' => $jpgFilename,
                    'template' => 'image',
                ]);
            }
        }
    } catch (\Exception $e) {
        throw new \Exception('[model-converter] compress texture exception: ' . $e->getMessage());
    } finally {
        if (file_exists($tmpPath)) @unlink($tmpPath);
    }
}

/**
 * Compress textures embedded inside a GLB file using gltf-transform.
 * Resizes all embedded textures to a max of 4096px and converts to WebP.
 * Modifies the file in-place (tmp → replace).
 */
function compressGlbTextures($file) {
    $candidates = [
        'C:\\Program Files\\nodejs\\npx.cmd',
        'C:\\Program Files (x86)\\nodejs\\npx.cmd',
    ];
    $npx = 'npx';
    foreach ($candidates as $c) {
        if (file_exists($c)) { $npx = $c; break; }
    }
    $glbPath = $file->root();
    $tmpPath = $glbPath . '.compressing.glb';

    // Step 1: resize embedded textures to max 4096 px
    $cmd1 = sprintf(
        '"%s" gltf-transform resize %s %s --width 4096 --height 4096 2>&1',
        $npx,
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
        '"%s" gltf-transform webp %s %s --quality 80 2>&1',
        $npx,
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
