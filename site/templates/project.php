<?php
// ── Access control ───────────────────────────────────────────────────────
// New visibility model (private / link / public) gates non-admin access.
// Backward-compat: pages without a `visibility` field fall back to status
// (listed → public, draft → private) via visibilityResolved().
//
// Admins always see the page (so the panel preview still works).
$panelUser = $kirby->user();
$isAdmin   = $panelUser && $panelUser->isAdmin();

if (!$isAdmin) {
    $sharedKey = get('key');
    if (!$page->canBeViewedWithToken($sharedKey)) {
        // Use Kirby's standard 404 so private pages don't leak their existence.
        $kirby->response()->code(404);
        echo $site->errorPage()->render();
        exit;
    }
}

// Section visibility helper — admins see everything; everyone else respects
// the per-section toggles set on the page.
$canSee = function (string $section) use ($page, $isAdmin) {
    return $isAdmin || $page->sectionVisible($section);
};

// Define before snippet('header') so the title-bar markup below can use it.
$isEmbedded = !empty(get('embed'));
// viewer=only is an extra mode used by the CMS panel preview iframe —
// hides the sidebar entirely so the viewer fills the iframe edge to edge.
$isViewerOnly = $isEmbedded && get('viewer') === 'only';

// ── Visitor-mode chrome ──────────────────────────────────────────────
// Anyone WITHOUT a panel session (shared-link recipients, casual
// browsers landing on a public project) gets a stripped-down header:
// small wordmark + single "Carte des projets" link, no full site nav.
// Admin and other logged-in users keep the full nav because they
// actually navigate the site. Embedded mode still strips chrome
// entirely (iframe consumers don't want anything but the viewer).
$isVisitor = !$panelUser && !$isEmbedded;

snippet('header', ['isVisitor' => $isVisitor]);

// DRACO decoder ships with three.js. We use the local copy in
// node_modules so we don't rely on external CDNs which can fail
// on the first load due to network/DNS timeouts.
$dracoPath = url('node_modules/three/examples/jsm/libs/draco/');

// Canonical filenames are set by the upload-overwrite plugin at upload time.
// We prefer the canonical name; field UUID is kept as secondary fallback.
// Canonical names set on upload; fall back to field UUID, then any file by extension/type
$objFile          = $page->file('exterior.obj')         ?? $page->model_obj()->toFile();
$interiorObjFile  = $page->file('interior.obj')         ?? $page->model_obj_interior()->toFile();

$texFile          = $page->file('exterior-texture.webp') ?? $page->file('exterior-texture.jpg') ?? $page->file('exterior-texture.png')
                    ?? $page->file('exterior-texture.jpeg') ?? $page->model_texture()->toFile();
$normFile         = $page->file('exterior-normal.jpg')  ?? $page->file('exterior-normal.png')
                    ?? $page->file('exterior-normal.jpeg') ?? $page->model_normal()->toFile();
$interiorTexFile  = $page->file('interior-texture.webp') ?? $page->file('interior-texture.jpg') ?? $page->file('interior-texture.png')
                    ?? $page->file('interior-texture.jpeg') ?? $page->model_texture_interior()->toFile();
$interiorNormFile = $page->file('interior-normal.jpg')  ?? $page->file('interior-normal.png')
                    ?? $page->file('interior-normal.jpeg') ?? $page->model_normal_interior()->toFile();

// Progressive loading previews (auto-generated 1024 px JPEG companions)
$texPreviewFile         = $texFile
    ? $page->file(pathinfo($texFile->filename(), PATHINFO_FILENAME) . '-preview.jpg') : null;
$interiorTexPreviewFile = $interiorTexFile
    ? $page->file(pathinfo($interiorTexFile->filename(), PATHINFO_FILENAME) . '-preview.jpg') : null;

$hotspotsExtFile = $page->file('hotspots-exterior.json') ?? $page->model_hotspots_json()->toFile();
$hotspotsIntFile = $page->file('hotspots-interior.json') ?? $page->model_hotspots_json_interior()->toFile();
$hotspotsExtUrl  = $hotspotsExtFile ? $hotspotsExtFile->url() : null;
$hotspotsIntUrl  = $hotspotsIntFile ? $hotspotsIntFile->url() : null;

$viewerUrl = $page->viewer_url()->isNotEmpty() ? $page->viewer_url()->esc() : null;
$viewerLabel = $page->viewer_label()->isNotEmpty() ? $page->viewer_label()->esc() : 'Explorer le Modèle 3D';

// Build annotation data from the unified `annotations` structure
// (each row carries a `location: exterior|interior` value). Legacy
// pages with the old separate `annotations_interior` field are still
// folded in via the `allAnnotations()` page method until they're
// migrated by scripts/migrate-annotations.php.
$annotationsData = [];
foreach ($page->allAnnotations() as $ann) {
    $annotationsData[] = [
        'id'          => $ann->hotspot_id()->value(),
        'title'       => $ann->title()->value(),
        'description' => $ann->description()->value(),
        'camera_mode' => $ann->camera_mode()->or('fly')->value(),
        'location'    => $ann->location()->or('exterior')->value(),
    ];
}
$annotationsJson = json_encode($annotationsData, JSON_UNESCAPED_UNICODE);

$objUrl           = $objFile          ? $objFile->url()          : null;
$interiorObjUrl   = $interiorObjFile  ? $interiorObjFile->url()  : null;
$interiorTexUrl   = $interiorTexFile  ? $interiorTexFile->url()  : null;
$interiorNormUrl  = $interiorNormFile ? $interiorNormFile->url() : null;
$texUrl           = $texFile ? $texFile->url() : null;
$normUrl          = $normFile ? $normFile->url() : null;
$texPreviewUrl         = $texPreviewFile         ? $texPreviewFile->url()         : null;
$interiorTexPreviewUrl = $interiorTexPreviewFile ? $interiorTexPreviewFile->url() : null;

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

// Visibility: when the owner has not exposed the 3D model section, suppress
// the viewer/iframe entirely and fall through to the poster image. Admins
// keep full access (handled via $canSee).
if (!$canSee('model')) {
    $hasIframe = false;
    $hasModel  = false;
}

// Annotations follow the same gating: if hidden, blank the JSON so the
// viewer doesn't render hotspot markers at all.
if (!$canSee('annotations')) {
    $annotationsJson = '[]';
}
$defaultSide = $page->model_toggle()->isTrue() ? 'interior' : 'exterior';

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

// ── View-mode chips ─────────────────────────────────────────────────────
// The right-hand viewer area can swap between 3D model, fullscreen image
// gallery, and fullscreen plans. Each mode is its own pane inside
// #viewer-container; floating chips on top let the visitor switch.
//
// A mode only contributes a chip + pane when it has content AND the
// owner has opted to expose it via visibility ($canSee). When only one
// mode is available we suppress the chip row entirely — a single chip
// with no alternatives is just noise.
$plansList      = $page->plans();
$hasGalleryPane = $canSee('gallery') && $gallery->count() > 0;
$hasPlansPane   = $canSee('plans')   && $plansList && $plansList->count() > 0;
// The model pane is always present — even when there's nothing to show it
// falls back to the cover image / "Vue 3D prochainement" placeholder, which
// is the page's intended hero. So we don't gate it on $hasModel.
$hasModelPane   = true;

$availableModes = [];
if ($hasModelPane)   $availableModes[] = 'model';
if ($hasGalleryPane) $availableModes[] = 'gallery';
if ($hasPlansPane)   $availableModes[] = 'plans';
$showModeChips  = count($availableModes) > 1;
// Default mode = first available. Model is always first, so this
// effectively means "3D when possible, else gallery, else plans".
$defaultMode = $availableModes[0] ?? 'model';
?>

<div class="items-start pt-0 pb-10">

    <?php if (!$isEmbedded && !$isVisitor): ?>
    <!-- Back button (admins / logged-in users only — visitors already
         have the "Carte des projets" CTA in the simplified header). -->
    <div class="col-7 flex items-center mb-6 px-4 pt-4 md:pt-6">
        <a href="<?= $page->parent()->url() ?>" class="inline-flex items-center gap-3 font-mono text-sm md:text-base uppercase tracking-wider text-faint hover:text-ink transition-colors duration-150 no-underline p-2 md:p-3 -m-2 md:-m-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="md:w-6 md:h-6">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
            Retour
        </a>
    </div>
    <?php endif ?>

    <!-- ── Content and Viewer Wrapper for Animation ── -->
    <div class="project-panels-wrapper<?= $isViewerOnly ? ' is-viewer-only' : '' ?>">
        <!-- ── Left: Content & Specs (2 cols) — becomes bottom drawer on mobile ── -->
        <div class="flex flex-col gap-8 pb-10 project-content" id="project-content">

            <!-- mobile drawer handle (hidden on desktop) -->
            <div class="project-drawer__handle" id="project-drawer-handle">
                <div class="project-drawer__bar"></div>
                <span class="project-drawer__label">Informations</span>
            </div>

            <!-- Project title — lives inside the foldable sidebar on all breakpoints -->
            <div class="project-sidebar-title">
                <h1 class="font-thyssen"><?= $page->title()->esc() ?></h1>
                <?php snippet('location-tag', ['location' => $page->location()->value()]) ?>
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
            <?php if ($hasSpecs && $canSee('info')): ?>
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

            <!-- Plans now have their own pane in the viewer area
                 (rendered above in the right column). We still need the
                 OpenSeadragon modal + script on the page so clicks on
                 plan tiles can open the fullscreen viewer — that's what
                 modalOnly=true gives us. The sidebar no longer shows
                 a duplicate plans list. -->
            <?php if ($canSee('plans')) snippet('plan-viewer', ['modalOnly' => true]) ?>

            <!-- Tags -->
            <div class="flex flex-wrap gap-2 mt-4">
                <?php foreach ($page->tags()->split(',') as $tag): ?>
                    <a href="<?= url('map') ?>?tag=<?= urlencode(trim($tag)) ?><?= $isEmbedded ? '&embed=1' : '' ?>" class="tag"><?= esc(trim($tag)) ?></a>
                <?php endforeach ?>
            </div>

            <!-- Gallery + plans no longer live in the sidebar — they
                 each get their own fullscreen pane in the viewer area
                 (switch via the chips on top of the viewer). -->

        </div>

        <!-- Desktop fold toggle for the info panel -->
        <button type="button" id="project-fold-toggle" class="fold-toggle" aria-label="Afficher/masquer les informations" title="Afficher/masquer les informations">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
            </svg>
        </button>

        <!-- ── Right: Viewer (5 cols, sticky) ──
             The viewer area now hosts up to three swappable panes:
             3D model, image gallery, and plans grid. The chip overlay
             on top lets the visitor switch between them; chips for
             empty modes are suppressed server-side. -->
        <div class="sticky overflow-hidden rounded-md relative bg-ink z-50"
             id="viewer-container"
             data-default-mode="<?= esc($defaultMode) ?>"
             style="top: 80px; height: calc(100vh - 100px); min-height: 500px;">

        <?php if ($showModeChips): ?>
        <!-- Floating mode chips (top, centered) ─────────────────────── -->
        <div class="viewer-mode-chips" role="tablist" aria-label="Mode d'affichage">
            <?php
            $modeIcons = [
                'model'   => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
                'gallery' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
                'plans'   => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
            ];
            $modeLabels = [
                'model'   => 'Modèle 3D',
                'gallery' => 'Galerie',
                'plans'   => 'Plans',
            ];
            foreach ($availableModes as $mode):
                $isDefault = ($mode === $defaultMode);
            ?>
            <button
                type="button"
                class="viewer-mode-chip<?= $isDefault ? ' is-active' : '' ?>"
                data-mode-target="<?= esc($mode) ?>"
                role="tab"
                aria-selected="<?= $isDefault ? 'true' : 'false' ?>"
                aria-controls="viewer-pane-<?= esc($mode) ?>"
            >
                <?= $modeIcons[$mode] ?>
                <span><?= esc($modeLabels[$mode]) ?></span>
            </button>
            <?php endforeach ?>
        </div>
        <?php endif ?>

        <!-- ── MODEL PANE ─────────────────────────────────────────────── -->
        <div class="viewer-pane viewer-pane--model<?= $defaultMode === 'model' ? ' is-active' : '' ?>"
             id="viewer-pane-model"
             data-mode-pane="model"
             role="tabpanel">

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
                 <?php if ($texPreviewUrl): ?>data-texture-preview="<?= $texPreviewUrl ?>"<?php endif ?>
                 data-normal="<?= $normUrl ?>"
                 <?php if ($interiorObjUrl):  ?>data-obj-interior="<?= $interiorObjUrl ?>"<?php endif ?>
                 <?php if ($interiorGlbUrl):  ?>data-glb-interior="<?= $interiorGlbUrl ?>"<?php endif ?>
                 <?php if ($interiorTexUrl):  ?>data-texture-interior="<?= $interiorTexUrl ?>"<?php endif ?>
                 <?php if ($interiorTexPreviewUrl): ?>data-texture-interior-preview="<?= $interiorTexPreviewUrl ?>"<?php endif ?>
                 <?php if ($interiorNormUrl): ?>data-normal-interior="<?= $interiorNormUrl ?>"<?php endif ?>
                 data-draco-path="<?= $dracoPath ?>"
                 data-annotations="<?= htmlspecialchars($annotationsJson) ?>"
                 <?php if ($hotspotsExtUrl): ?>data-hotspots-json="<?= $hotspotsExtUrl ?>"<?php endif ?>
                 <?php if ($hotspotsIntUrl): ?>data-hotspots-json-interior="<?= $hotspotsIntUrl ?>"<?php endif ?>
                 data-default-side="<?= $defaultSide ?>">
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
        <!-- End MODEL PANE -->

        <!-- ── GALLERY PANE ───────────────────────────────────────────── -->
        <?php if ($hasGalleryPane): ?>
        <div class="viewer-pane viewer-pane--gallery<?= $defaultMode === 'gallery' ? ' is-active' : '' ?>"
             id="viewer-pane-gallery"
             data-mode-pane="gallery"
             role="tabpanel">
            <div class="viewer-gallery">
                <?php foreach ($gallery as $idx => $image):
                    // Larger crops here than the sidebar thumbnails because
                    // the viewer pane gets the full 5-col width. Lazy-load
                    // everything past the first image so scroll-down doesn't
                    // block initial paint.
                ?>
                <a href="<?= $image->url() ?>"
                   data-lightbox
                   class="viewer-gallery__item"
                   aria-label="<?= $image->alt()->or($page->title())->esc() ?>">
                    <img
                        src="<?= $image->crop(800, 600)->url() ?>"
                        srcset="<?= $image->crop(800, 600)->url() ?> 1x, <?= $image->crop(1600, 1200)->url() ?> 2x"
                        alt="<?= $image->alt()->or($page->title())->esc() ?>"
                        loading="<?= $idx === 0 ? 'eager' : 'lazy' ?>"
                    >
                </a>
                <?php endforeach ?>
            </div>
        </div>
        <?php endif ?>

        <!-- ── PLANS PANE ─────────────────────────────────────────────── -->
        <?php if ($hasPlansPane): ?>
        <div class="viewer-pane viewer-pane--plans<?= $defaultMode === 'plans' ? ' is-active' : '' ?>"
             id="viewer-pane-plans"
             data-mode-pane="plans"
             role="tabpanel">
            <div class="viewer-plans">
                <?php foreach ($plansList as $plan):
                    $ext      = strtolower($plan->extension());
                    $isRaster = in_array($ext, ['jpg','jpeg','png','webp','tif','tiff'], true);
                    $isPdf    = $ext === 'pdf';
                    $tilesUrl = ($isRaster || $isPdf) ? $page->planTiles($plan) : null;
                    $caption  = $plan->caption()->or($plan->filename())->value();
                    if ($isRaster) {
                        $thumbUrl = $plan->thumb(['width' => 480, 'height' => 360, 'crop' => true])->url();
                    } elseif ($isPdf && $tilesUrl) {
                        $thumbUrl = $page->planThumbUrl($plan, 480);
                    } else {
                        $thumbUrl = null;
                    }
                ?>
                <?php if (($isRaster || $isPdf) && $tilesUrl): ?>
                    <button
                        type="button"
                        class="viewer-plans__item"
                        data-plan-viewer
                        data-plan-tiles="<?= esc($tilesUrl) ?>"
                        data-plan-title="<?= esc($caption) ?>"
                        aria-label="<?= esc($caption) ?>"
                    >
                        <?php if ($thumbUrl): ?>
                            <img src="<?= esc($thumbUrl) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div class="viewer-plans__item-fallback">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                            </div>
                        <?php endif ?>
                        <span class="viewer-plans__caption">
                            <span class="viewer-plans__ext"><?= strtoupper($ext) ?></span>
                            <?= esc($caption) ?>
                        </span>
                    </button>
                <?php else: ?>
                    <a href="<?= $plan->url() ?>"
                       target="_blank" rel="noopener"
                       class="viewer-plans__item viewer-plans__item--download"
                       aria-label="Télécharger <?= esc($caption) ?>">
                        <div class="viewer-plans__item-fallback">
                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </div>
                        <span class="viewer-plans__caption">
                            <span class="viewer-plans__ext"><?= strtoupper($ext) ?></span>
                            <?= esc($caption) ?>
                        </span>
                    </a>
                <?php endif ?>
                <?php endforeach ?>
            </div>
        </div>
        <?php endif ?>


    </div>
    <!-- End project-panels-wrapper -->
    </div>

</div>

<?php if ($showModeChips): ?>
<script>
// Floating chip swapper — pure DOM, no framework. Active chip + pane share
// the .is-active class. Switching panes also dispatches a custom event so
// downstream JS (viewer.js, plan-viewer.js) can lazy-load resources on
// first activation.
(function () {
    var container = document.getElementById('viewer-container');
    if (!container) return;

    var chips = container.querySelectorAll('.viewer-mode-chip');
    var panes = container.querySelectorAll('[data-mode-pane]');

    function setMode(target) {
        chips.forEach(function (c) {
            var match = c.getAttribute('data-mode-target') === target;
            c.classList.toggle('is-active', match);
            c.setAttribute('aria-selected', match ? 'true' : 'false');
        });
        panes.forEach(function (p) {
            var match = p.getAttribute('data-mode-pane') === target;
            p.classList.toggle('is-active', match);
        });
        container.dispatchEvent(new CustomEvent('viewer:mode-change', { detail: { mode: target } }));
    }

    chips.forEach(function (c) {
        c.addEventListener('click', function () {
            setMode(c.getAttribute('data-mode-target'));
        });
    });
})();
</script>
<?php endif ?>

<?php snippet('footer') ?>
