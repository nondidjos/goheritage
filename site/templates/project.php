<?php
// Define before snippet('header') so the title-bar markup below can use it.
$isEmbedded = !empty(get('embed'));

snippet('header');

// DRACO decoder ships with three.js. Locally we reference the local copy in
// node_modules; in production we use the CDN-hosted decoder so node_modules
// doesn't have to be deployed. Keep the version in sync with the importmap
// in site/snippets/header.php.
$threeVersion = '0.183.2';
$dracoHost    = $kirby->environment()->host() ?? '';
$isLocalDev   = $dracoHost === 'localhost'
             || str_starts_with($dracoHost, '127.')
             || str_starts_with($dracoHost, '192.168.')
             || str_ends_with($dracoHost, '.test')
             || str_ends_with($dracoHost, '.local');
$dracoPath    = $isLocalDev
    ? url('node_modules/three/examples/jsm/libs/draco/')
    : 'https://unpkg.com/three@' . $threeVersion . '/examples/jsm/libs/draco/';

// Canonical filenames are set by the upload-overwrite plugin at upload time.
// We prefer the canonical name; field UUID is kept as secondary fallback.
// Canonical names set on upload; fall back to field UUID, then any file by extension/type
$objFile          = $page->file('exterior.obj')         ?? $page->model_obj()->toFile();
$interiorObjFile  = $page->file('interior.obj')         ?? $page->model_obj_interior()->toFile();

$texFile          = $page->file('exterior-texture.jpg') ?? $page->file('exterior-texture.png')
                    ?? $page->file('exterior-texture.jpeg') ?? $page->model_texture()->toFile();
$normFile         = $page->file('exterior-normal.jpg')  ?? $page->file('exterior-normal.png')
                    ?? $page->file('exterior-normal.jpeg') ?? $page->model_normal()->toFile();
$interiorTexFile  = $page->file('interior-texture.jpg') ?? $page->file('interior-texture.png')
                    ?? $page->file('interior-texture.jpeg') ?? $page->model_texture_interior()->toFile();
$interiorNormFile = $page->file('interior-normal.jpg')  ?? $page->file('interior-normal.png')
                    ?? $page->file('interior-normal.jpeg') ?? $page->model_normal_interior()->toFile();

$hotspotsExtFile = $page->file('hotspots-exterior.json') ?? $page->model_hotspots_json()->toFile();
$hotspotsIntFile = $page->file('hotspots-interior.json') ?? $page->model_hotspots_json_interior()->toFile();
$hotspotsExtUrl  = $hotspotsExtFile ? $hotspotsExtFile->url() : null;
$hotspotsIntUrl  = $hotspotsIntFile ? $hotspotsIntFile->url() : null;

$viewerUrl = $page->viewer_url()->isNotEmpty() ? $page->viewer_url()->esc() : null;
$viewerLabel = $page->viewer_label()->isNotEmpty() ? $page->viewer_label()->esc() : 'Explorer le Modèle 3D';

// Build annotation data from both exterior and interior CMS structure fields
$annotationsData = [];
foreach ([$page->annotations(), $page->annotations_interior()] as $field) {
    if ($field->isNotEmpty()) {
        foreach ($field->toStructure() as $ann) {
            $annotationsData[] = [
                'id'          => $ann->hotspot_id()->value(),
                'title'       => $ann->title()->value(),
                'description' => $ann->description()->value(),
                'camera_mode' => $ann->camera_mode()->or('fly')->value(),
            ];
        }
    }
}
$annotationsJson = json_encode($annotationsData, JSON_UNESCAPED_UNICODE);

$objUrl           = $objFile          ? $objFile->url()          : null;
$interiorObjUrl   = $interiorObjFile  ? $interiorObjFile->url()  : null;
$interiorTexUrl   = $interiorTexFile  ? $interiorTexFile->url()  : null;
$interiorNormUrl  = $interiorNormFile ? $interiorNormFile->url() : null;
$texUrl           = $texFile ? $texFile->url() : null;
$normUrl          = $normFile ? $normFile->url() : null;

// GLB: prefer canonical name, fall back to field UUID, then any GLB not already used as interior
$interiorGlbFile = $page->file('interior.glb');
$interiorGlbUrl  = $interiorGlbFile ? $interiorGlbFile->url() : null;

$glbFile = $page->file('exterior.glb') ?? ($objFile ? null
    : $page->files()->filterBy('extension', 'glb')
        ->filter(fn($f) => !$interiorObjFile || $f->id() !== $interiorObjFile->id())
        ->filter(fn($f) => !$interiorGlbFile || $f->id() !== $interiorGlbFile->id())
        ->sortBy('modified', 'desc')->first());
$glbUrl = $glbFile ? $glbFile->url() : null;

$hasIframe  = ($viewerUrl !== null);
$hasModel   = ($objUrl !== null || $interiorObjUrl !== null || $glbUrl !== null || $interiorGlbUrl !== null);

$posterUrl = ($cover = $page->cover()->toFile())
    ? $cover->crop(1600, 700)->url()
    : null;

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

    <?php if (!$isEmbedded): ?>
    <!-- Back button (top left) -->
    <div class="col-7 flex items-center mb-6 px-4 pt-4 md:pt-6">
        <a href="<?= $page->parent()->url() ?>" class="inline-flex items-center gap-3 font-mono text-sm md:text-base uppercase tracking-wider text-faint hover:text-ink transition-colors duration-150 no-underline p-2 md:p-3 -m-2 md:-m-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="md:w-6 md:h-6">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
            Retour
        </a>
    </div>
    <?php endif ?>

    <!-- Project Header (spans full 7 cols) -->
    <div class="col-7 flex flex-col items-center mb-4 px-4 pt-4 project-header relative">
        <h1 class="font-thyssen text-[clamp(2.5rem,8vw,6rem)] text-center leading-[0.9] mb-5 border-none no-underline"><?= $page->title()->esc() ?></h1>
        
        <!-- Location (right aligned, but closer in flow) -->
        <div class="flex justify-end w-full max-w-6xl pr-6">
            <?php snippet('location-tag', ['location' => $page->location()->value()]) ?>
        </div>
    </div>

    <!-- ── Content and Viewer Wrapper for Animation ── -->
    <div class="project-panels-wrapper">
        <!-- ── Left: Content & Specs (2 cols) — becomes bottom drawer on mobile ── -->
        <div class="flex flex-col gap-8 pb-10 project-content" id="project-content">

            <!-- mobile drawer handle (hidden on desktop) -->
            <div class="project-drawer__handle" id="project-drawer-handle">
                <div class="project-drawer__bar"></div>
                <span class="project-drawer__label">Informations</span>
            </div>

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
                    <h3 class="font-mono text-xs uppercase tracking-widest text-ink mb-3">Fiche technique</h3>
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
                    <a href="<?= url('map') ?>?tag=<?= urlencode(trim($tag)) ?><?= $isEmbedded ? '&embed=1' : '' ?>" class="tag"><?= esc(trim($tag)) ?></a>
                <?php endforeach ?>
            </div>

            <!-- Gallery -->
            <?php if ($gallery->count()): ?>
                <div>
                    <h3 class="font-mono text-xs uppercase tracking-widest text-ink mb-3">Galerie</h3>
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
        <div class="sticky overflow-hidden rounded-md relative bg-ink z-50" id="viewer-container" style="top: 80px; height: calc(100vh - 100px); min-height: 500px;">

        <!-- Desktop fold toggle for the info panel (hidden on mobile via CSS) -->
        <button type="button" id="project-fold-toggle" class="fold-toggle" aria-label="Afficher/masquer les informations" title="Afficher/masquer les informations">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
            </svg>
        </button>

        <?php if ($isEmbedded): ?>
        <!-- Embed mode splash screen: shows title, location, and play button -->
        <div id="embed-splash" class="absolute inset-0 z-50 flex flex-col items-center justify-center bg-ink transition-opacity duration-500">
            <!-- Blurred Background -->
            <?php if ($posterUrl): ?>
                <img src="<?= $posterUrl ?>" alt="" class="absolute inset-0 w-full h-full object-cover opacity-60 blur-md scale-105">
            <?php endif ?>
            
            <!-- Dark overlay to ensure text is readable -->
            <div class="absolute inset-0 bg-ink/40"></div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col items-center text-center px-6">
                <h1 class="font-thyssen text-4xl md:text-5xl text-white mb-3 drop-shadow-md"><?= $page->title()->esc() ?></h1>
                
                <div class="mb-8">
                    <?php snippet('location-tag', ['location' => $page->location()->value(), 'class' => '!text-white/80']) ?>
                </div>

                <button id="embed-play-btn" class="bg-white/10 backdrop-blur-md border border-white/20 rounded-full w-20 h-20 flex items-center justify-center transform transition-transform duration-300 hover:scale-110 hover:bg-white/20 cursor-pointer text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="currentColor" class="ml-2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                </button>
                
                <span class="font-sans text-xs text-white/80 mt-4 uppercase tracking-widest"><?= $viewerLabel ?></span>
            </div>
        </div>
        
        <script>
        document.getElementById('embed-play-btn').addEventListener('click', function() {
            var splash = document.getElementById('embed-splash');
            splash.style.opacity = '0';
            splash.style.pointerEvents = 'none';
            
            <?php if ($hasIframe): ?>
            const iframe = document.createElement('iframe');
            iframe.src = "<?= $viewerUrl ?>";
            iframe.className = "w-full h-full border-none";
            iframe.allowFullscreen = true;
            iframe.allow = "xr-spatial-tracking; fullscreen";
            document.getElementById('iframe-container').appendChild(iframe);
            document.body.classList.add('viewer-is-ready');
            <?php elseif ($hasModel): ?>
            var viewer = document.getElementById('viewer-3d');
            if (viewer) {
                viewer.dispatchEvent(new Event('goheritage:load'));
                // viewer-is-ready is added by viewer.js once the model finishes loading
            }
            <?php endif ?>
        });
        </script>
        <?php endif ?>

        <?php if ($hasIframe): ?>
            <?php if (!$isEmbedded): ?>
            <div id="model-poster" class="absolute inset-0 cursor-pointer z-10 transition-opacity duration-500">
                <?php if ($posterUrl): ?>
                    <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
                <?php else: ?>
                    <div class="w-full h-full bg-mid"></div>
                <?php endif ?>
                <div class="absolute inset-0 bg-ink/30 flex items-center justify-center transition-colors group-hover:bg-ink/40">
                    <div class="bg-white/10 backdrop-blur-md border border-white/20 rounded-full w-20 h-20 flex items-center justify-center transform transition-transform duration-300 group-hover:scale-110">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="white" class="ml-2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </div>
                </div>
                <div class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-ink/80 backdrop-blur-md px-4 py-2 rounded-full">
                    <span class="font-sans text-xs text-white"><?= $viewerLabel ?></span>
                </div>
            </div>
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
            <?php endif ?>
            <div id="iframe-container" class="absolute inset-0 z-0"></div>

        <?php elseif ($hasModel): ?>
            <div id="viewer-3d"
                 class="w-full h-full bg-ink"
                 data-defer-load="<?= $isEmbedded ? 'true' : 'false' ?>"
                 data-obj="<?= $objUrl ?>"
                 <?php if ($glbUrl): ?>data-glb="<?= $glbUrl ?>"<?php endif ?>
                 data-texture="<?= $texUrl ?>"
                 data-normal="<?= $normUrl ?>"
                 <?php if ($interiorObjUrl):  ?>data-obj-interior="<?= $interiorObjUrl ?>"<?php endif ?>
                 <?php if ($interiorGlbUrl):  ?>data-glb-interior="<?= $interiorGlbUrl ?>"<?php endif ?>
                 <?php if ($interiorTexUrl):  ?>data-texture-interior="<?= $interiorTexUrl ?>"<?php endif ?>
                 <?php if ($interiorNormUrl): ?>data-normal-interior="<?= $interiorNormUrl ?>"<?php endif ?>
                 data-draco-path="<?= $dracoPath ?>"
                 data-annotations="<?= htmlspecialchars($annotationsJson) ?>"
                 <?php if ($hotspotsExtUrl): ?>data-hotspots-json="<?= $hotspotsExtUrl ?>"<?php endif ?>
                 <?php if ($hotspotsIntUrl): ?>data-hotspots-json-interior="<?= $hotspotsIntUrl ?>"<?php endif ?>>
            </div>
            <style>
              .viewer-progress {
                position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
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
            <?php if ($posterUrl): ?>
                <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
            <?php else: ?>
                <div class="w-full h-full bg-mid"></div>
            <?php endif ?>
            <div class="absolute inset-0 bg-ink/30 flex items-center justify-center pointer-events-none">
                <span class="font-mono text-sm uppercase tracking-widest text-white px-6 py-2 border border-white/30 backdrop-blur-md rounded-sm">Vue 3D prochainement</span>
            </div>
        <?php endif ?>


    </div>
    <!-- End project-panels-wrapper -->
    </div>

</div>

<?php snippet('footer') ?>
