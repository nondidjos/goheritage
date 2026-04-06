<?php
snippet('header');

// auto-detect 3d model files from this page's directory
// explicit field reference (stored by upload-overwrite after each upload) takes
// priority; falls back to the most-recently-modified file of the right type.
$glbFile  = $page->model_glb()->toFile()
    ?? $page->files()->filterBy('extension', 'glb')->sortBy('modified', 'desc')->first();
$interiorGlbFile  = $page->model_glb_interior()->toFile();
$interiorObjFile  = $page->model_obj_interior()->toFile();
$interiorTexFile  = $page->model_texture_interior()->toFile();
$interiorNormFile = $page->model_normal_interior()->toFile();
$objFile  = $page->model_obj()->toFile()
    ?? $page->files()->filterBy('extension', 'obj')->sortBy('modified', 'desc')->first();
$texFile  = $page->model_texture()->toFile()
    ?? $page->files()->filterBy('extension', 'in', ['png', 'jpg', 'jpeg'])
               ->filter(fn($f) => str_ends_with($f->name(), '-compressed'))->first()
    ?? $page->files()->filterBy('extension', 'in', ['png', 'jpg', 'jpeg'])
               ->filter(function($f) {
                   $name = strtolower($f->filename());
                   return str_starts_with($name, 'texture_')
                       || strpos($name, 'diffuse') !== false
                       || strpos($name, 'color') !== false;
               })
               ->first();

$normFile = $page->model_normal()->toFile()
    ?? $page->files()->filterBy('extension', 'in', ['png', 'jpg', 'jpeg'])
               ->filter(function($f) {
                   $name = strtolower($f->filename());
                   return str_starts_with($name, 'normal_')
                       || strpos($name, 'normal') !== false;
               })
               ->first();

$viewerUrl = $page->viewer_url()->isNotEmpty() ? $page->viewer_url()->esc() : null;
$viewerLabel = $page->viewer_label()->isNotEmpty() ? $page->viewer_label()->esc() : 'Explorer le Modèle 3D';

// build annotation data from CMS structure field
$annotationsData = [];
if ($page->annotations()->isNotEmpty()) {
    foreach ($page->annotations()->toStructure() as $i => $ann) {
        $annotationsData[] = [
            'id'          => $ann->hotspot_id()->value(),
            'title'       => $ann->title()->value(),
            'description' => $ann->description()->value(),
            'camera_mode' => $ann->camera_mode()->or('fly')->value(),
        ];
    }
}
$annotationsJson = json_encode($annotationsData, JSON_UNESCAPED_UNICODE);

$glbUrl           = $glbFile         ? $glbFile->url()         : null;
$interiorGlbUrl   = $interiorGlbFile  ? $interiorGlbFile->url()  : null;
$interiorObjUrl   = $interiorObjFile  ? $interiorObjFile->url()  : null;
$interiorTexUrl   = $interiorTexFile  ? $interiorTexFile->url()  : null;
$interiorNormUrl  = $interiorNormFile ? $interiorNormFile->url() : null;
$objUrl           = $objFile          ? $objFile->url()          : null;
$texUrl          = $texFile ? $texFile->url() : null;
$normUrl = $normFile ? $normFile->url() : null;

$hasIframe  = ($viewerUrl !== null);
$hasModel   = ($glbUrl !== null || $objUrl !== null || $interiorGlbUrl !== null || $interiorObjUrl !== null);

$posterUrl = ($cover = $page->cover()->toFile())
    ? $cover->crop(1600, 700)->url()
    : url('assets/hero-images/Seattle-Art-Museum-good-scan-60070.jpg');

$gallery = $page->gallery()->toFiles();
if ($gallery->count() === 0) {
    $gallery = $page->images()
        ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp'])
        ->filter(fn($f) => !str_contains(strtolower($f->filename()), 'diffuse')
                        && !str_contains(strtolower($f->filename()), 'texture')
                        && !str_contains(strtolower($f->filename()), 'normal_'))
        ->sortBy('sort');
}
?>

<div class="items-start pt-0 pb-10">

    <!-- Project Header (spans full 7 cols) -->
    <div class="col-7 flex flex-col items-center mb-4 px-4 pt-8 project-header relative">
        <h1 class="font-thyssen text-[clamp(2.5rem,8vw,6rem)] text-center leading-[0.9] mb-2 border-none no-underline"><?= $page->title()->esc() ?></h1>
        
        <!-- Location (right aligned, but closer in flow) -->
        <div class="flex justify-end w-full max-w-6xl pr-6">
            <?php snippet('location-tag', ['location' => $page->location()->value()]) ?>
        </div>
    </div>

    <!-- ── Left: Content & Specs (2 cols) ── -->
    <div class="col-2 flex flex-col gap-8 pb-10">

        <!-- Rich text blocks -->
        <?php if ($page->text()->isNotEmpty()): ?>
            <div class="font-serif text-base text-ink leading-relaxed [&_h2]:font-sans [&_h2]:text-xl [&_h2]:mb-3 [&_h2]:mt-8 [&_p]:mb-4">
                <?= $page->text()->toBlocks() ?>
            </div>
        <?php endif ?>

        <!-- Spec Sheet — monospace minimal -->
        <?php
        $specFields = [
            ['label' => 'Construction', 'value' => $page->construction_date()],
            ['label' => 'Architecte',   'value' => $page->architect()],
            ['label' => 'Style',        'value' => $page->style()],
            ['label' => 'Dimensions',   'value' => $page->dimensions()],
            ['label' => 'Protection',   'value' => $page->protection_status()],
        ];
        $hasSpecs = false;
        foreach ($specFields as $sf) { if ($sf['value']->isNotEmpty()) { $hasSpecs = true; break; } }
        ?>
        <?php if ($hasSpecs): ?>
            <div class="spec-card">
                <h3 class="spec-card__heading">Fiche technique</h3>
                <dl class="spec-card__grid">
                    <?php foreach ($specFields as $sf): ?>
                        <?php if ($sf['value']->isNotEmpty()): ?>
                            <div class="spec-card__item">
                                <dt class="spec-card__label"><?= $sf['label'] ?></dt>
                                <dd class="spec-card__value"><?= $sf['value']->esc() ?></dd>
                            </div>
                        <?php endif ?>
                    <?php endforeach ?>
                </dl>
            </div>
        <?php endif ?>

        <!-- Tags -->
        <div class="flex flex-wrap gap-2 mt-4">
            <?php foreach ($page->tags()->split(',') as $tag): ?>
                <a href="<?= url('blog') ?>?tag=<?= urlencode(trim($tag)) ?>" class="tag"><?= esc(trim($tag)) ?></a>
            <?php endforeach ?>
        </div>

        <!-- Gallery -->
        <?php if ($gallery->count()): ?>
            <div>
                <h3 class="font-mono text-xs uppercase tracking-widest text-faint mb-3">Galerie</h3>
                <div class="grid grid-cols-2 gap-2">
                    <?php foreach ($gallery as $image): ?>
                        <a href="<?= $image->url() ?>" data-lightbox
                           class="block aspect-square overflow-hidden rounded-md bg-border transition-transform hover:-translate-y-0.5 cursor-zoom-in">
                            <img src="<?= $image->crop(400, 400)->url() ?>"
                                 alt="<?= $image->alt()->or($page->title())->esc() ?>"
                                 loading="lazy" class="w-full h-full object-cover">
                        </a>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endif ?>

    </div>

    <!-- ── Right: 3D Viewer (5 cols, sticky) ── -->
    <div class="col-5 sticky overflow-hidden rounded-md relative bg-ink z-50" id="viewer-container" style="top: 80px; height: calc(100vh - 100px); min-height: 500px;">

        <?php if ($hasIframe): ?>
            <div id="model-poster" class="absolute inset-0 cursor-pointer z-10 transition-opacity duration-500">
                <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-ink/30 flex items-center justify-center transition-colors group-hover:bg-ink/40">
                    <div class="bg-white/10 backdrop-blur-md border border-white/20 rounded-full w-20 h-20 flex items-center justify-center transform transition-transform duration-300 group-hover:scale-110">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="white" class="ml-2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </div>
                </div>
                <div class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-ink/80 backdrop-blur-md px-4 py-2 rounded-full">
                    <span class="font-sans text-xs text-white"><?= $viewerLabel ?></span>
                </div>
            </div>
            <div id="iframe-container" class="absolute inset-0 z-0"></div>
            <script>
            document.getElementById('model-poster').addEventListener('click', function() {
                this.style.opacity = '0';
                this.style.pointerEvents = 'none';
                const iframe = document.createElement('iframe');
                iframe.src = "<?= $viewerUrl ?>";
                iframe.className = "w-full h-full border-none";
                iframe.allowFullscreen = true;
                iframe.allow = "xr-spatial-tracking; fullscreen";
                document.getElementById('iframe-container').appendChild(iframe);
            });
            </script>

        <?php elseif ($hasModel): ?>
            <div id="viewer-3d"
                 class="w-full h-full bg-ink"
                 data-glb="<?= $glbUrl ?>"
                 <?php if ($interiorGlbUrl):  ?>data-glb-interior="<?= $interiorGlbUrl ?>"<?php endif ?>
                 <?php if ($interiorObjUrl):  ?>data-obj-interior="<?= $interiorObjUrl ?>"<?php endif ?>
                 <?php if ($interiorTexUrl):  ?>data-texture-interior="<?= $interiorTexUrl ?>"<?php endif ?>
                 <?php if ($interiorNormUrl): ?>data-normal-interior="<?= $interiorNormUrl ?>"<?php endif ?>
                 data-obj="<?= $objUrl ?>"
                 data-texture="<?= $texUrl ?>"
                 data-normal="<?= $normUrl ?>"
                 data-draco-path="<?= url('node_modules/three/examples/jsm/libs/draco/') ?>"
                 data-annotations="<?= htmlspecialchars($annotationsJson) ?>">
            </div>
            <style>
              .viewer-progress {
                position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%);
                width: 250px; text-align: center; pointer-events: none; z-index: 100;
                transition: opacity 0.4s;
              }
              .viewer-progress-bar {
                width: 100%; height: 2px;
                background: rgba(255,255,255,0.2);
                border-radius: 2px; margin-bottom: 8px; overflow: hidden;
              }
              .viewer-progress-fill {
                width: 0%; height: 100%;
                background: #fff;
                transition: width 0.2s;
              }
              .viewer-progress-text {
                font-family: var(--font-mono, monospace);
                font-size: 11px; text-transform: uppercase;
                letter-spacing: 0.08em; color: rgba(255,255,255,0.6);
              }
            </style>

        <?php else: ?>
            <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
            <div class="absolute inset-0 bg-ink/30 flex items-center justify-center pointer-events-none">
                <span class="font-mono text-sm uppercase tracking-widest text-white px-6 py-2 border border-white/30 backdrop-blur-md rounded-sm">Vue 3D prochainement</span>
            </div>
        <?php endif ?>

    </div>

</div>

<?php snippet('footer') ?>
