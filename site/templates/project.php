<?php
snippet('header');

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

// ── PanoViewer (panorama) assets ────────────────────────────────────────────
// Prefer the explicit field selection; fall back to any panorama-templated file.
$panoFiles = $page->pano_files()->toFiles();
if ($panoFiles->count() === 0) {
    $panoFiles = $page->images()->template('panorama');
}

// Resize panoramas on the fly via Kirby thumbs.
// Raw equirects from Matterport are typically 8192×4096 @ 30-50MB each; we serve
// a 4096-wide JPEG (~1-2MB) as the viewable resolution, plus a 1024-wide preview
// for dollhouse markers and initial paint. Cube faces are usually smaller so the
// thumb is mostly a no-op for them. Originals stay on disk for download.
//
// The preview key is indexed by BASENAME (lowercased, no extension) so the JS
// bootstrap can rewrite panorama URLs coming from the GoHéritage JSON file —
// the JSON stores raw filenames and has no knowledge of the thumb cache.
$PANO_MAX_W     = 4096;   // full viewable resolution
$PANO_PREVIEW_W = 1024;   // thumb used as preview / pre-heat
$PANO_QUALITY   = 85;

$panoUrls    = [];        // ordered list of viewable URLs (thumbs)
$panoScenes  = [];        // [{ url, preview, filename }]
$panoRewrite = [];        // basename-without-ext  ⇒ { url, preview }
foreach ($panoFiles as $pf) {
    $ext = strtolower($pf->extension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;
    // Matterport cube faces are small (≈512–1024 px each) AND the viewer's
    // SKYBOX_REGEX requires filenames ending in `<prefix>_skybox<N>.ext` —
    // Kirby thumbs append `-WIDTHx` which breaks that pattern. Serve originals.
    $isCubeFace = (bool) preg_match('/[-_]skybox[-_]?\d/i', $pf->filename());
    if ($isCubeFace) {
        // Skybox filenames must keep the `_skybox{N}` pattern intact (the
        // SKYBOX_REGEX in the viewer relies on it for grouping faces). Kirby
        // thumbs append `-WIDTHx` which breaks that, so we only use the
        // ORIGINAL URL as the canonical face URL. The low-res preview is
        // generated separately and exposed via panoRewrite[stem].preview —
        // the viewer reads it for an LOD pre-pass during scene swaps.
        $fullUrl = $pf->url();
        try {
            $prevUrl = $pf->thumb([
                'width'   => $PANO_PREVIEW_W,
                'quality' => 72,
                'format'  => 'jpg',
            ])->url();
        } catch (\Throwable $e) {
            $prevUrl = $pf->url();
        }
    } else {
        try {
            $fullThumb = $pf->thumb([
                'width'   => $PANO_MAX_W,
                'quality' => $PANO_QUALITY,
                'format'  => 'jpg',
            ]);
            $prevThumb = $pf->thumb([
                'width'   => $PANO_PREVIEW_W,
                'quality' => 75,
                'format'  => 'jpg',
            ]);
            $fullUrl = $fullThumb->url();
            $prevUrl = $prevThumb->url();
        } catch (\Throwable $e) {
            // Fallback to original if thumb generation fails (e.g. GD missing).
            $fullUrl = $pf->url();
            $prevUrl = $pf->url();
        }
    }
    $panoUrls[]   = $fullUrl;
    $panoScenes[] = [
        'filename' => $pf->filename(),
        'url'      => $fullUrl,
        'preview'  => $prevUrl,
    ];
    $stem = strtolower(pathinfo($pf->filename(), PATHINFO_FILENAME));
    $panoRewrite[$stem] = ['url' => $fullUrl, 'preview' => $prevUrl];
}

$panoHotspotsFile = $page->file('pano-hotspots.json') ?? $page->pano_hotspots_json()->toFile();
$panoHotspotsUrl  = $panoHotspotsFile ? $panoHotspotsFile->url() : null;

$hasIframe  = ($viewerUrl !== null);
$hasModel   = ($objUrl !== null || $interiorObjUrl !== null || $glbUrl !== null || $interiorGlbUrl !== null);
$hasPano    = (count($panoUrls) > 0 || $panoHotspotsUrl !== null);

// Resolve viewer choice. iframe > preference > fallback.
$viewerPref = $page->viewer_preference()->or('auto')->value();
$usePano  = false;
$useModel = false;
if (!$hasIframe) {
    if ($viewerPref === 'model')         { $useModel = $hasModel; $usePano = !$hasModel && $hasPano; }
    elseif ($viewerPref === 'panorama')  { $usePano  = $hasPano;  $useModel = !$hasPano  && $hasModel; }
    else                                 { $usePano  = $hasPano;  $useModel = !$hasPano  && $hasModel; }
}

$posterUrl = ($cover = $page->cover()->toFile())
    ? $cover->crop(1600, 700)->url()
    : null;

$gallery = $page->gallery()->toFiles();
if ($gallery->count() === 0) {
    $gallery = $page->images()
        ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp'])
        ->filter(fn($f) => $f->template() !== 'panorama')
        ->filter(fn($f) => !str_contains(strtolower($f->filename()), 'diffuse')
                        && !str_contains(strtolower($f->filename()), 'texture')
                        && !str_contains(strtolower($f->filename()), 'normal_'))
        ->sortBy('sort');
}
?>

<div class="items-start pt-0 pb-10">

    <!-- Project Header (spans full 7 cols) -->
    <div class="col-7 flex flex-col items-center mb-4 px-4 pt-4 project-header relative">
        <h1 class="font-thyssen text-[clamp(2.5rem,8vw,6rem)] text-center leading-[0.9] mb-5 border-none no-underline"><?= $page->title()->esc() ?></h1>
        
        <!-- Location (right aligned, but closer in flow) -->
        <div class="flex justify-end w-full max-w-6xl pr-6">
            <?php snippet('location-tag', ['location' => $page->location()->value()]) ?>
        </div>
    </div>

    <!-- ── Left: Content & Specs (2 cols) — becomes bottom drawer on mobile ── -->
    <div class="col-2 flex flex-col gap-8 pb-10 project-content" id="project-content">

        <!-- mobile drawer handle (hidden on desktop) -->
        <div class="project-drawer__handle" id="project-drawer-handle">
            <div class="project-drawer__bar"></div>
            <span class="project-drawer__label">Informations</span>
        </div>

        <!-- ── Section: HISTOIRE ─────────────────────────────────────── -->
        <section class="project-section project-section--history">
            <header class="project-section__header">
                <span class="project-section__eyebrow">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Histoire & patrimoine</span>
                </span>
            </header>

            <?php if ($page->text()->isNotEmpty()): ?>
                <div class="font-serif text-base text-ink leading-relaxed [&_h2]:font-sans [&_h2]:text-xl [&_h2]:mb-3 [&_h2]:mt-8 [&_p]:mb-4">
                    <?= $page->text()->toBlocks() ?>
                </div>
            <?php endif ?>
        </section>

        <!-- ── Section: COMMERCIAL ────────────────────────────────────── -->
        <?php if ($page->isBookable() || $page->ownerContact()): ?>
        <section class="project-section project-section--commercial">
            <header class="project-section__header">
                <span class="project-section__eyebrow project-section__eyebrow--commercial">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 12V7H4v10h6"></path>
                        <path d="M12 22s4-3 4-8a4 4 0 1 0-8 0c0 5 4 8 4 8z"></path>
                    </svg>
                    <span>Réserver & visiter</span>
                </span>
                <?php if ($page->commercial_priceFrom()->isNotEmpty()): ?>
                    <span class="project-section__price">
                        À partir de <?= $page->commercial_priceFrom()->esc() ?>&nbsp;€
                    </span>
                <?php endif ?>
            </header>

            <?php if ($page->commercial_pitch()->isNotEmpty()): ?>
                <p class="font-serif text-sm text-mid leading-relaxed mb-5">
                    <?= $page->commercial_pitch()->esc() ?>
                </p>
            <?php endif ?>

            <?php
            $availabilities = $page->availabilities();
            $catalog = availabilityCatalog();
            ?>
            <?php if ($availabilities): ?>
                <div class="availability-grid">
                    <?php foreach ($availabilities as $key): if (!isset($catalog[$key])) continue; $meta = $catalog[$key]; ?>
                        <a href="<?= url('contact') ?>?as=visitor&property=<?= urlencode($page->title()->value()) ?>&subject=<?= urlencode($key) ?>"
                           class="availability-chip"
                           data-activity="<?= esc($key, 'attr') ?>">
                            <span class="availability-chip__icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <?php switch ($meta['icon']):
                                        case 'walk': ?>
                                            <circle cx="13" cy="4" r="2"></circle><path d="M9 22l3-9 3 4 4 3"></path><path d="M9 13l-3-3 3-3"></path>
                                            <?php break; case 'heart': ?>
                                            <path d="M12 21s-7-4.35-7-11a4.5 4.5 0 0 1 8-2.85A4.5 4.5 0 0 1 19 10c0 6.65-7 11-7 11z"></path>
                                            <?php break; case 'briefcase': ?>
                                            <rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"></path>
                                            <?php break; case 'camera': ?>
                                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle>
                                            <?php break; case 'bed': ?>
                                            <path d="M2 4v16"></path><path d="M22 8v12"></path><path d="M2 14h20"></path><path d="M2 8h12a4 4 0 0 1 4 4"></path>
                                            <?php break; case 'star': ?>
                                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                                            <?php break; endswitch; ?>
                                </svg>
                            </span>
                            <span class="availability-chip__label"><?= $meta['label'] ?></span>
                            <span class="availability-chip__arrow" aria-hidden="true">→</span>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <?php $owner = $page->ownerContact(); if ($owner): ?>
                <div class="owner-card">
                    <p class="owner-card__eyebrow">Contact propriétaire</p>
                    <?php if (!empty($owner['name'])): ?>
                        <p class="owner-card__name"><?= esc($owner['name']) ?></p>
                    <?php endif ?>
                    <ul class="owner-card__list">
                        <?php if (!empty($owner['email'])): ?>
                            <li><a href="mailto:<?= esc($owner['email'], 'attr') ?>"><?= esc($owner['email']) ?></a></li>
                        <?php endif ?>
                        <?php if (!empty($owner['phone'])): ?>
                            <li><a href="tel:<?= esc(preg_replace('/\s+/', '', $owner['phone']), 'attr') ?>"><?= esc($owner['phone']) ?></a></li>
                        <?php endif ?>
                        <?php if (!empty($owner['website'])): ?>
                            <li><a href="<?= esc($owner['website'], 'attr') ?>" target="_blank" rel="noopener">Site web →</a></li>
                        <?php endif ?>
                    </ul>
                </div>
            <?php endif ?>
        </section>
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

        <!-- Tags — region (auto-derived) shown first, then user tags -->
        <div class="flex flex-wrap gap-2 mt-4">
            <?php if ($region = $page->region()): ?>
                <a href="<?= url('map') ?>?region=<?= urlencode($region) ?>"
                   class="tag tag--region"><?= esc($page->regionLabel()) ?></a>
            <?php endif ?>
            <?php foreach ($page->tags()->split(',') as $tag): ?>
                <a href="<?= url('map') ?>?tag=<?= urlencode(trim($tag)) ?>" class="tag"><?= esc(trim($tag)) ?></a>
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
    <div class="col-5 sticky overflow-hidden rounded-md relative bg-ink z-50" id="viewer-container" style="top: 80px; height: calc(100vh - 100px); min-height: 500px;">

        <?php if ($hasIframe): ?>
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

        <?php elseif ($usePano): ?>
            <?php
            // Base URL for relative panorama paths inside the GoHéritage JSON
            $panoBaseUrl = $panoFiles->count() > 0 ? dirname($panoFiles->first()->url()) : $page->url();
            // Prefer GLB over OBJ for dollhouse model (smaller, Draco-compressed)
            $dollhouseModelUrl = $glbUrl ?: $objUrl;
            $dollhouseTexUrl   = $texUrl;
            // Fall back to interior if exterior is missing
            if (!$dollhouseModelUrl) {
                $dollhouseModelUrl = $interiorGlbUrl ?: $interiorObjUrl;
                $dollhouseTexUrl   = $interiorTexUrl;
            }
            ?>
            <div id="pano-viewer"
                 class="pano-viewer w-full h-full"
                 data-pano-urls="<?= htmlspecialchars(json_encode($panoUrls, JSON_UNESCAPED_SLASHES)) ?>"
                 data-pano-scenes="<?= htmlspecialchars(json_encode($panoScenes, JSON_UNESCAPED_SLASHES)) ?>"
                 data-pano-rewrite="<?= htmlspecialchars(json_encode($panoRewrite, JSON_UNESCAPED_SLASHES)) ?>"
                 data-pano-base-url="<?= $panoBaseUrl ?>"
                 <?php if ($panoHotspotsUrl):   ?>data-goheritage-url="<?= $panoHotspotsUrl ?>"<?php endif ?>
                 <?php if ($dollhouseModelUrl): ?>data-model-url="<?= $dollhouseModelUrl ?>"<?php endif ?>
                 <?php if ($dollhouseTexUrl):   ?>data-model-texture="<?= $dollhouseTexUrl ?>"<?php endif ?>
                 <?php if ($page->marker_offset_x()->isNotEmpty()): ?>data-marker-offset-x="<?= $page->marker_offset_x() ?>"<?php endif ?>
                 <?php if ($page->marker_offset_y()->isNotEmpty()): ?>data-marker-offset-y="<?= $page->marker_offset_y() ?>"<?php endif ?>
                 <?php if ($page->marker_offset_z()->isNotEmpty()): ?>data-marker-offset-z="<?= $page->marker_offset_z() ?>"<?php endif ?>
                 data-draco-path="<?= url('node_modules/three/examples/jsm/libs/draco/') ?>">
            </div>

        <?php elseif ($useModel): ?>
            <div id="viewer-3d"
                 class="w-full h-full bg-ink"
                 data-obj="<?= $objUrl ?>"
                 <?php if ($glbUrl): ?>data-glb="<?= $glbUrl ?>"<?php endif ?>
                 data-texture="<?= $texUrl ?>"
                 data-normal="<?= $normUrl ?>"
                 <?php if ($interiorObjUrl):  ?>data-obj-interior="<?= $interiorObjUrl ?>"<?php endif ?>
                 <?php if ($interiorGlbUrl):  ?>data-glb-interior="<?= $interiorGlbUrl ?>"<?php endif ?>
                 <?php if ($interiorTexUrl):  ?>data-texture-interior="<?= $interiorTexUrl ?>"<?php endif ?>
                 <?php if ($interiorNormUrl): ?>data-normal-interior="<?= $interiorNormUrl ?>"<?php endif ?>
                 data-draco-path="<?= url('node_modules/three/examples/jsm/libs/draco/') ?>"
                 data-annotations="<?= htmlspecialchars($annotationsJson) ?>"
                 <?php if ($hotspotsExtUrl): ?>data-hotspots-json="<?= $hotspotsExtUrl ?>"<?php endif ?>
                 <?php if ($hotspotsIntUrl): ?>data-hotspots-json-interior="<?= $hotspotsIntUrl ?>"<?php endif ?>>
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

</div>

<?php snippet('footer') ?>
