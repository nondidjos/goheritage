<?php
// ── Access control ───────────────────────────────────────────────────────
// New visibility model (private / link / public) gates non-panel access.
// Backward-compat: pages without a `visibility` field fall back to status
// (listed → public, draft → private) via visibilityResolved().
//
// $panelUser / $canSee / every other derived value below come from
// site/controllers/project.php. This gate is kept here, at the very top of
// the template, on purpose: it's the single most security-sensitive check
// in this file, so it stays somewhere a reviewer trips over it immediately
// rather than inside a controller they might not think to open.
if (!$panelUser) {
    $sharedKey = get('key');
    if (!$page->canBeViewedWithToken($sharedKey)) {
        // Use Kirby's standard 404 so private pages don't leak their existence.
        $kirby->response()->code(404);
        echo $site->errorPage()->render();
        exit;
    }
}

// ── Visitor-mode chrome ──────────────────────────────────────────────
// Anyone WITHOUT a panel session (shared-link recipients, casual
// browsers landing on a public project) gets a stripped-down header:
// small wordmark + single "Carte des projets" link, no full site nav.
// Admin and other logged-in users keep the full nav because they
// actually navigate the site. Embedded mode still strips chrome
// entirely (iframe consumers don't want anything but the viewer).
// Project pages use the SAME site header as the rest of the site (the
// standalone back-to-map button below lives outside the header). We keep
// the variable name for the back-button gate, but never switch to the
// stripped "visitor" header here.

// ── Point-cloud preview (?pointcloud=1) ──────────────────────────────────
// A self-contained view used by the panel's "Nuage de points" tab: render
// ONLY the point-cloud viewer, or point to an external viewer, or explain
// what's missing — then return so the full 3D-model layout below never runs.
if (!empty(get('pointcloud'))) {
    snippet('header', ['isVisitor' => $isVisitor]);
    snippet('pointcloud-embed', compact('page', 'pcDots', 'pcExternal', 'pcCopc', 'pcInline', 'pcOther'));
    snippet('footer');
    return;
}

snippet('header', ['isVisitor' => $isVisitor]);
?>

<div class="items-start pt-0 pb-10">

    <!-- ── Content and Viewer Wrapper for Animation ── -->
    <div class="project-panels-wrapper<?= $isViewerOnly ? ' is-viewer-only' : '' ?>">
        <!-- ── Left: Content & Specs (2 cols) — becomes bottom drawer on mobile ── -->
        <div class="flex flex-col gap-8 pb-10 project-content" id="project-content">

            <!-- mobile drawer handle (hidden on desktop) — draggable up/down -->
            <div class="project-drawer__handle" id="project-drawer-handle">
                <div class="project-drawer__bar"></div>
                <span class="project-drawer__label">Informations</span>
            </div>

            <!-- Back to map — lives at the top of the info panel -->
            <?php if (!$isEmbedded): ?>
            <a href="<?= $page->parent()->url() ?>" class="project-back inline-flex items-center gap-2 font-mono text-xs uppercase tracking-wider text-faint hover:text-ink transition-colors duration-150 no-underline">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
                Retour à la carte
            </a>
            <?php endif ?>

            <!-- Project title — lives inside the foldable sidebar on all breakpoints -->
            <div class="project-sidebar-title">
                <h1 class="font-thyssen"><?= $page->title()->esc() ?></h1>
                <?php snippet('location-tag', ['location' => $page->location()->value()]) ?>
            </div>

            <!-- Rich text blocks -->
            <?php if ($page->text()->isNotEmpty()): ?>
                <div class="font-serif text-[1.0625rem] text-ink leading-[1.85] [&_h2]:font-sans [&_h2]:text-xl [&_h2]:font-medium [&_h2]:mb-4 [&_h2]:mt-10 [&_p]:mb-5 [&_p]:tracking-[0.01em]">
                    <?= $page->text()->toBlocks() ?>
                </div>
            <?php endif ?>

            <!-- Spec Sheet — monospace minimal -->
            <?php if ($hasSpecs && $canSee('info')): ?>
                <div class="spec-card">
                    <h3 class="font-mono text-xs uppercase tracking-widest text-ink mb-3">Fiche technique</h3>
                    <dl class="spec-card__grid">
                        <?php foreach ($specFields as $sf): ?>
                            <?php if (!empty($sf['value'])): ?>
                                <div class="spec-card__item">
                                    <dt class="spec-card__label"><?= $sf['label'] ?></dt>
                                    <dd class="spec-card__value"><?= esc($sf['value']) ?></dd>
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

            <!-- Tags. A tag is only linkified if at least one LISTED
                 project on the map actually carries it (see $liveTags,
                 site/controllers/project.php) — otherwise the link would
                 land on the map filtered for a tag that yields zero
                 results. Orphan tags render as plain labels so the info
                 is still shown but isn't a dead-end. -->
            <div class="flex flex-wrap gap-2 mt-4">
                <?php foreach ($page->tags()->split(',') as $tag): ?>
                    <?php $t = trim($tag); if ($t === '') continue; ?>
                    <?php if (isset($liveTags[mb_strtolower($t)])): ?>
                        <a href="<?= url('map') ?>?tag=<?= urlencode($t) ?><?= $isEmbedded ? '&embed=1' : '' ?>" class="tag"><?= esc($t) ?></a>
                    <?php else: ?>
                        <span class="tag tag--static"><?= esc($t) ?></span>
                    <?php endif ?>
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
        <div class="viewer-mode-bar">
        <div class="viewer-modes" id="viewer-switch" role="tablist" aria-label="Mode d'affichage">
            <?php foreach ($availableModes as $mode):
                $isDefault = ($mode === $defaultMode);
            ?>
            <button type="button"
                class="viewer-modes__btn<?= $isDefault ? ' is-active' : '' ?>"
                role="tab"
                data-mode-target="<?= esc($mode) ?>"
                aria-selected="<?= $isDefault ? 'true' : 'false' ?>"
                title="<?= esc($modeLabels[$mode]) ?>">
                <span class="viewer-modes__ico"><?= $modeIcons[$mode] ?></span>
                <span class="viewer-modes__label"><?= esc($modeLabels[$mode]) ?></span>
            </button>
            <?php endforeach ?>
        </div>
        </div>
        <?php endif ?>

        <div class="viewer-stage">
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
                    <?php snippet('icon-play') ?>
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

        // ?autoplay=1 — skip the "press play" splash and load the viewer
        // immediately. Used by the CMS panel's Modèle 3D preview, where the
        // model is the main subject rather than a teaser embed.
        <?php if (get('autoplay')): ?>
        (function () {
            function run() {
                var btn = document.getElementById('embed-play-btn');
                if (!btn) return;
                btn.click();
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run);
            } else {
                setTimeout(run, 0);
            }
        })();
        <?php endif ?>
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
                        <?php snippet('icon-play', ['color' => 'white']) ?>
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

        <?php else: ?>
            <!-- Empty-state. Background is full-bleed (poster dimmed, or a flat
                 dark fill matching the viewer); the badge is a separate node so
                 it can be re-centred in the visible area beside the fold-out
                 sidebar (see .viewer-empty in custom.css). -->
            <div class="viewer-empty-bg absolute inset-0">
                <?php if ($posterUrl): ?>
                    <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
                    <div class="absolute inset-0" style="background: rgba(26,25,22,0.45);"></div>
                <?php else: ?>
                    <div class="w-full h-full" style="background: var(--color-ink, #1a1a1a);"></div>
                <?php endif ?>
            </div>
            <div class="viewer-empty">
                <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/><line x1="3.5" y1="20.5" x2="20.5" y2="3.5"/></svg>
                <span class="font-mono text-xs uppercase tracking-widest">Aucun modèle 3D</span>
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
                    // All thumbs are WebP, generated by the Sharp component
                    // (memory-safe on big originals). Grid: an 800×600 crop +
                    // a 2× for retina. Lightbox opens a 2000px-capped WebP, not
                    // the raw multi-MB original. width/height keep the grid from
                    // reflowing as images stream in; everything past the first
                    // is lazy + async-decoded.
                    $g1x = $image->thumb(['width' => 800,  'height' => 600,  'crop' => true, 'format' => 'webp', 'quality' => 74]);
                    $g2x = $image->thumb(['width' => 1600, 'height' => 1200, 'crop' => true, 'format' => 'webp', 'quality' => 68]);
                    $gFull = $image->thumb(['width' => 2000, 'format' => 'webp', 'quality' => 82]);
                ?>
                <a href="<?= $gFull->url() ?>"
                   data-lightbox
                   class="viewer-gallery__item"
                   aria-label="<?= $image->alt()->or($page->title())->esc() ?>">
                    <img
                        src="<?= $g1x->url() ?>"
                        srcset="<?= $g1x->url() ?> 1x, <?= $g2x->url() ?> 2x"
                        width="800" height="600"
                        alt="<?= $image->alt()->or($page->title())->esc() ?>"
                        loading="<?= $idx === 0 ? 'eager' : 'lazy' ?>"
                        decoding="async"
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

        <!-- ── POINT CLOUD PANE ───────────────────────────────────────────
             Lazy iframe: viewer-modes.js injects it from data-pc-src on first
             activation, so we don't boot a second WebGL context unless the
             visitor actually opens it. -->
        <?php if ($hasPointcloudPane): ?>
        <div class="viewer-pane viewer-pane--pointcloud<?= $defaultMode === 'pointcloud' ? ' is-active' : '' ?>"
             id="viewer-pane-pointcloud"
             data-mode-pane="pointcloud"
             data-pc-src="<?= esc($page->url()) ?>?embed=1&pointcloud=1<?= get('key') ? '&key=' . urlencode(get('key')) : '' ?>"
             role="tabpanel"></div>
        <?php endif ?>

        </div>

    </div>
    <!-- End project-panels-wrapper -->
    </div>

</div>

<?php // viewer-modes.js wires the mode buttons AND lazily injects the point-cloud
      // iframe — so it's needed whenever there's a switcher OR a point-cloud pane
      // (a point-cloud-only project has no switcher but still needs its iframe). ?>
<?php if ($showModeChips || $hasPointcloudPane): ?>
<script src="<?= ghAsset('assets/js/viewer-modes.js') ?>" defer></script>
<?php endif ?>

<?php snippet('footer') ?>
