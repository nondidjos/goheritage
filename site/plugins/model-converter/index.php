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

Kirby::plugin('goheritage/model-converter', [

    // ── Panel field ──────────────────────────────────────────────────────────
    'fields' => [
        'upload-overwrite' => [
            'computed' => [
                'pageId' => function () {
                    return $this->model()->id();
                },
                'files' => function () {
                    return $this->model()->files()->values(fn($f) => [
                        'filename' => $f->filename(),
                        'url'      => $f->url(),
                        'id'       => $f->id(),
                    ]);
                },
            ],
        ],
    ],

    // ── Custom API routes ─────────────────────────────────────────────────────
    'api' => [
        'routes' => [
            [
                'pattern' => 'goheritage/upload-overwrite',
                'method'  => 'POST',
                'action'  => function () {
                    $kirby   = kirby();
                    $request = $kirby->request();

                    $pageId   = $request->get('pageId');
                    $template = $request->get('template', 'default');

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

                    $tmpPath  = $uploaded['tmp_name'];
                    $filename = basename($uploaded['name']);

                    try {
                        $kirby->impersonate('kirby');
                        $existing = $page->file($filename);

                        if ($existing) {
                            // Overwrite — bypasses FileRules::notExistingFile()
                            $newFile = $existing->replace($tmpPath);
                        } else {
                            // First upload
                            $newFile = $page->createFile([
                                'source'   => $tmpPath,
                                'filename' => $filename,
                                'template' => $template,
                            ]);
                        }

                        return Response::json([
                            'status'   => $existing ? 'replaced' : 'created',
                            'filename' => $newFile->filename(),
                            'url'      => $newFile->url(),
                        ]);

                    } catch (\Throwable $e) {
                        return Response::json(['error' => $e->getMessage()], 500);
                    }
                },
            ],
        ],
    ],

    // ── File hooks ────────────────────────────────────────────────────────────
    'hooks' => [
        // fires after a file has been created (uploaded) in the panel
        'file.create:after' => function ($file) {
            if ($file->extension() === 'obj') {
                convertObjToGlb($file);
            } elseif (in_array(strtolower($file->extension()), ['png', 'jpg', 'jpeg'])) {
                $page = $file->parent();
                if ($page && $page->compress_textures()->toBool()) {
                    compressTexture($file);
                }
            }
        },

        // fires after a file has been replaced (re-uploaded via panel default)
        'file.replace:after' => function ($newFile, $oldFile) {
            if ($newFile->extension() === 'obj') {
                convertObjToGlb($newFile);
            } elseif (in_array(strtolower($newFile->extension()), ['png', 'jpg', 'jpeg'])) {
                $page = $newFile->parent();
                if ($page && $page->compress_textures()->toBool()) {
                    compressTexture($newFile);
                }
            }
        },
    ],
]);

/**
 * convert an obj file to draco-compressed glb using node cli tools
 */
function convertObjToGlb($file) {
    $npx = 'npx';

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
    $npx      = 'npx';
    $srcPath  = $file->root();
    $dir      = dirname($srcPath);
    $basename = pathinfo($srcPath, PATHINFO_FILENAME);
    $destPath = $dir . '/' . $basename . '-compressed.jpg';

    $cmd = sprintf(
        '%s sharp -i %s -o %s format jpeg --quality 80 resize 8192 8192 --fit inside --withoutEnlargement 2>&1',
        $npx,
        escapeshellarg($srcPath),
        escapeshellarg($destPath)
    );
    $output = []; $code = 0;
    exec($cmd, $output, $code);

    if ($code !== 0 || !file_exists($destPath)) {
        error_log('[model-converter] sharp-cli texture compression failed: ' . implode("\n", $output));
        return;
    }

    try {
        $page = $file->parent();
        if ($page) {
            kirby()->impersonate('kirby');
            $destFilename = $basename . '-compressed.jpg';
            $existing = $page->file($destFilename);
            if ($existing) {
                $existing->replace($destPath);
            } else {
                $page->createFile([
                    'source'   => $destPath,
                    'filename' => $destFilename,
                    'template' => 'image',
                ]);
            }
        }
    } catch (\Exception $e) {
        error_log('[model-converter] compressed texture registration: ' . $e->getMessage());
    }
}
