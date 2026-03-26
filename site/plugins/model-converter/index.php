<?php

/**
 * model-converter plugin
 * automatically converts uploaded .obj files to draco-compressed .glb
 * requires obj2gltf and @gltf-transform/cli (installed via npm)
 */

use Kirby\Cms\App as Kirby;

Kirby::plugin('goheritage/model-converter', [
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

        // fires after a file has been replaced (re-uploaded)
        'file.replace:after' => function ($newFile, $oldFile) {
            if ($newFile->extension() === 'obj') {
                convertObjToGlb($newFile);
            } elseif (in_array(strtolower($newFile->extension()), ['png', 'jpg', 'jpeg'])) {
                $page = $newFile->parent();
                if ($page && $page->compress_textures()->toBool()) {
                    compressTexture($newFile);
                }
            }
        }
    ]
]);

/**
 * convert an obj file to draco-compressed glb using node cli tools
 */
function convertObjToGlb($file) {
    $root = kirby()->root('index');
    $npx = 'npx';

    // paths
    $objPath = $file->root();
    $dir = dirname($objPath);
    $basename = pathinfo($objPath, PATHINFO_FILENAME);
    $tmpGlb = $dir . '/' . $basename . '-tmp.glb';
    $finalGlb = $dir . '/' . $basename . '.glb';

    // step 1: obj → glb (binary gltf with embedded textures)
    $cmd1 = sprintf(
        '%s obj2gltf -i %s -o %s --binary --unlit 2>&1',
        $npx,
        escapeshellarg($objPath),
        escapeshellarg($tmpGlb)
    );

    $output1 = [];
    $code1 = 0;
    exec($cmd1, $output1, $code1);

    if ($code1 !== 0 || !file_exists($tmpGlb)) {
        error_log('[model-converter] obj2gltf failed: ' . implode("\n", $output1));
        return;
    }

    // step 2: apply draco compression
    $cmd2 = sprintf(
        '%s gltf-transform draco %s %s 2>&1',
        $npx,
        escapeshellarg($tmpGlb),
        escapeshellarg($finalGlb)
    );

    $output2 = [];
    $code2 = 0;
    exec($cmd2, $output2, $code2);

    // clean up the intermediate file
    if (file_exists($tmpGlb)) {
        unlink($tmpGlb);
    }

    if ($code2 !== 0 || !file_exists($finalGlb)) {
        error_log('[model-converter] gltf-transform draco failed: ' . implode("\n", $output2));
        return;
    }

    // register the new glb file with kirby so it appears in the panel
    try {
        $page = $file->parent();
        if ($page) {
            $page->createFile([
                'source'   => $finalGlb,
                'filename' => $basename . '.glb',
                'template' => 'model'
            ]);
        }
    } catch (\Exception $e) {
        // glb might already be registered — that's fine
        error_log('[model-converter] glb registration: ' . $e->getMessage());
    }
}

/**
 * convert a large texture to optimized jpeg using sharp-cli
 */
function compressTexture($file) {
    $npx = 'npx';
    $srcPath = $file->root();
    $dir = dirname($srcPath);
    $basename = pathinfo($srcPath, PATHINFO_FILENAME);
    $destPath = $dir . '/' . $basename . '-compressed.jpg';

    // use sharp-cli to convert to jpeg and compress
    // limits max dimension to 8192 if it's absurdly large, 
    // applies mozjpeg compression at quality 80
    $cmd = sprintf(
        '%s sharp -i %s -o %s format jpeg --quality 80 resize 8192 8192 --fit inside --withoutEnlargement 2>&1',
        $npx,
        escapeshellarg($srcPath),
        escapeshellarg($destPath)
    );

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);

    if ($code !== 0 || !file_exists($destPath)) {
        error_log('[model-converter] sharp-cli texture compression failed: ' . implode("\n", $output));
        return;
    }

    try {
        $page = $file->parent();
        if ($page) {
            // register the new compressed texture with kirby
            $page->createFile([
                'source'   => $destPath,
                'filename' => $basename . '-compressed.jpg',
                'template' => 'image'
            ]);
        }
    } catch (\Exception $e) {
        error_log('[model-converter] compressed texture registration: ' . $e->getMessage());
    }
}
