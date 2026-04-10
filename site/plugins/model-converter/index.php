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
F::$types['document'][] = 'json';

Kirby::plugin('goheritage/model-converter', [

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
                    ]);
                },
            ],
        ],
        'accordion-trigger' => [],
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

                    set_time_limit(120);
                    $kirby->impersonate('kirby');
                    compressTexture($file);

                    return Response::json(['status' => 'ok']);
                },
            ],
            [
                'pattern' => 'goheritage/upload-overwrite',
                'method'  => 'POST',
                'auth'    => false,
                'action'  => function () {
                    // Temporarily raise limits just for this heavy API route
                    ini_set('memory_limit', '-1');
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
                        if ($fieldName) {
                            $page->update([$fieldName => $newFile->uuid()]);
                        }

                        // Manually trigger post-upload processing since the
                        // custom route bypasses Kirby's file.create:after hook.
                        $isInterior = in_array($fieldName, [
                            'model_obj_interior', 'model_texture_interior', 'model_normal_interior'
                        ]);
                        $shouldCompress = $isInterior
                            ? $page->compress_textures_interior()->toBool()
                            : $page->compress_textures()->toBool();

                        if ($ext === 'obj') {
                            convertObjToGlb($newFile);
                        } elseif ($ext === 'glb' && $shouldCompress) {
                            compressGlbTextures($newFile);
                        } elseif (in_array($ext, ['png', 'jpg', 'jpeg']) && $shouldCompress) {
                            compressTexture($newFile);
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
            $page = $file->parent();
            $ext  = strtolower($file->extension());

            if ($ext === 'obj') {
                convertObjToGlb($file);
            } elseif ($ext === 'glb') {
                if ($page && $page->compress_textures()->toBool()) {
                    compressGlbTextures($file);
                }
            } elseif (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                if ($page && $page->compress_textures()->toBool()) {
                    compressTexture($file);
                }
            }
        },

        // fires after a file has been replaced (re-uploaded via panel default)
        'file.replace:after' => function ($newFile, $oldFile) {
            $page = $newFile->parent();
            $ext  = strtolower($newFile->extension());

            if ($ext === 'obj') {
                convertObjToGlb($newFile);
            } elseif ($ext === 'glb') {
                if ($page && $page->compress_textures()->toBool()) {
                    compressGlbTextures($newFile);
                }
            } elseif (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                if ($page && $page->compress_textures()->toBool()) {
                    compressTexture($newFile);
                }
            }
        },
    ],
]);

/**
 * Maps a blueprint field name to the canonical filename base used on disk.
 * Returns null for fields that should keep the original upload name.
 */
function goheritageCanonicalBase($fieldName) {
    static $map = [
        'model_obj'              => 'exterior',
        'model_obj_interior'     => 'interior',
        'model_texture'          => 'exterior-texture',
        'model_texture_interior' => 'interior-texture',
        'model_normal'           => 'exterior-normal',
        'model_normal_interior'  => 'interior-normal',
        'model_hotspots_json'    => 'hotspots',
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

    // step 1: obj → glb
    $cmd1 = sprintf(
        '%s obj2gltf -i %s -o %s --binary --unlit 2>&1',
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
        '%s gltf-transform draco %s %s 2>&1',
        $npx,
        escapeshellarg($tmpGlb),
        escapeshellarg($finalGlb)
    );
    $output2 = []; $code2 = 0;
    exec($cmd2, $output2, $code2);

    if (file_exists($tmpGlb)) unlink($tmpGlb);

    if ($code2 !== 0 || !file_exists($finalGlb)) {
        error_log('[model-converter] gltf-transform draco failed: ' . implode("\n", $output2));
        return;
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
function compressTexture($file) {
    $nodeCandidates = [
        'C:\\Program Files\\nodejs\\node.exe',
        'C:\\Program Files (x86)\\nodejs\\node.exe',
    ];
    $node = null;
    foreach ($nodeCandidates as $c) {
        if (file_exists($c)) { $node = $c; break; }
    }
    if (!$node) {
        error_log('[model-converter] node.exe not found');
        return;
    }

    $script   = __DIR__ . '/compress-texture.js';
    $srcPath  = $file->root();
    $dir      = dirname($srcPath);
    $basename = pathinfo($srcPath, PATHINFO_FILENAME);
    $tmpPath  = $dir . '/' . $basename . '-tmp.jpg';

    $cmd = sprintf('%s %s %s %s 2>&1', $node, escapeshellarg($script), escapeshellarg($srcPath), escapeshellarg($tmpPath));
    $output = []; $code = 0;
    exec($cmd, $output, $code);

    if ($code !== 0 || !file_exists($tmpPath)) {
        error_log('[model-converter] compress-texture.js failed: ' . implode("\n", $output));
        return;
    }

    try {
        $page = $file->parent();
        if ($page) {
            kirby()->impersonate('kirby');
            $jpgFilename = $basename . '.jpg';
            $jpgRoot     = $dir . '/' . $jpgFilename;
            $existing    = $page->file($jpgFilename);
            if ($existing) {
                $existing->replace($tmpPath);
            } elseif (file_exists($jpgRoot)) {
                // File on disk but not in Kirby registry (created manually) — overwrite directly
                copy($tmpPath, $jpgRoot);
            } else {
                $page->createFile([
                    'source'   => $tmpPath,
                    'filename' => $jpgFilename,
                    'template' => 'image',
                ]);
            }
            if (strtolower($file->extension()) !== 'jpg') {
                try { $file->delete(); } catch (\Throwable $_) {}
            }
        }
    } catch (\Exception $e) {
        error_log('[model-converter] compress texture registration: ' . $e->getMessage());
    } finally {
        @unlink($tmpPath);
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
        '%s gltf-transform resize %s %s --width 4096 --height 4096 2>&1',
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
        '%s gltf-transform webp %s %s --quality 80 2>&1',
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
