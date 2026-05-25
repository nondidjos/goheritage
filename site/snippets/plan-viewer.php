<?php
/**
 * plan-viewer snippet
 *
 * Drop-in plans list + modal viewer. Add to a template with one line:
 *
 *     <?php snippet('plan-viewer') ?>
 *
 * Renders nothing when the current page has no plans. Otherwise:
 *   • Vertical list of plan files with proper icons, captions, scale/level.
 *   • Click on a raster plan → opens OpenSeadragon in a fullscreen modal,
 *     streaming DeepZoom tiles (no full-res download URL exposed).
 *   • Click on a PDF / DWG / SVG → falls back to download / new-tab.
 *
 * Plan files are discovered via $page->plans() (template=plan, or filename
 * heuristic). Tiles are generated automatically on upload by the plugin.
 *
 * OpenSeadragon is loaded lazily from CDN on first click — no cost when
 * the page has no plans or the user never opens one.
 */

$plans = $page->plans();
if (!$plans || $plans->count() === 0) return;

// $modalOnly: when true, suppress the inline plans list and render only
// the OpenSeadragon modal + script. Used by the project template, which
// now renders its own plans grid in the viewer pane and just needs the
// modal infrastructure to be present once on the page.
$modalOnly = $modalOnly ?? false;
?>

<?php if (!$modalOnly): ?>
<section class="plan-viewer-section" data-plan-viewer-section>
    <h3 class="plan-viewer-section__heading">Plans &amp; relevés</h3>

    <ul class="plan-viewer-list">
        <?php foreach ($plans as $plan):
            $ext        = strtolower($plan->extension());
            $isRaster   = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'], true);
            $isPdf      = $ext === 'pdf';
            $isCad      = in_array($ext, ['dwg', 'dxf'], true);
            $isVector   = $ext === 'svg';
            // Both rasters and PDFs can have tiles — generate-tiles.js routes
            // PDFs through pdftoppm before tiling.
            $tilesUrl   = ($isRaster || $isPdf) ? $page->planTiles($plan) : null;
            $caption    = $plan->caption()->or($plan->filename())->value();
            $scale      = $plan->scale()->value();
            $level      = $plan->level()->value();
            $hasMeta    = $scale || $level;
            // Thumbnail URL:
            //   • rasters: Kirby crop (fast, cached, supports any aspect)
            //   • PDFs:    a single DZI tile at the right zoom level (picked
            //              by planThumbUrl from the manifest)
            //   • other:   fall back to the type icon
            $thumbUrl = null;
            if ($isRaster) {
                $thumbUrl = $plan->thumb(['width' => 240, 'height' => 160, 'crop' => true])->url();
            } elseif ($isPdf && $tilesUrl) {
                $thumbUrl = $page->planThumbUrl($plan, 256);
            }
        ?>
            <li class="plan-viewer-list__item">
                <?php if (($isRaster || $isPdf) && $tilesUrl): ?>
                    <button type="button"
                            class="plan-viewer-list__btn"
                            data-plan-viewer
                            data-plan-tiles="<?= esc($tilesUrl) ?>"
                            data-plan-title="<?= esc($caption) ?>">
                        <span class="plan-viewer-list__thumb"<?= $thumbUrl ? ' style="background-image:url(' . esc($thumbUrl) . ')"' : '' ?>>
                            <span class="plan-viewer-list__zoom-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </span>
                    </button>
                <?php else: ?>
                    <a href="<?= $plan->url() ?>" target="_blank" rel="noopener"
                       class="plan-viewer-list__btn plan-viewer-list__btn--fallback">
                        <span class="plan-viewer-list__thumb plan-viewer-list__thumb--icon">
                            <?php if ($isPdf): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <?php elseif ($isCad): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                            <?php elseif ($isVector): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6l2 3h12v15a2 2 0 0 1-2 2H2z"/></svg>
                            <?php else: ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                            <?php endif ?>
                        </span>
                    </a>
                <?php endif ?>

                <div class="plan-viewer-list__body">
                    <div class="plan-viewer-list__caption"><?= esc($caption) ?></div>
                    <div class="plan-viewer-list__meta">
                        <span class="plan-viewer-list__chip"><?= strtoupper($ext) ?></span>
                        <?php if ($hasMeta): ?>
                            <?php if ($level): ?><span><?= esc($level) ?></span><?php endif ?>
                            <?php if ($scale): ?><span><?= esc($scale) ?></span><?php endif ?>
                        <?php else: ?>
                            <span><?= $plan->niceSize() ?></span>
                        <?php endif ?>
                        <?php if (!$isRaster): ?>
                            <span class="plan-viewer-list__note">
                                <?php if ($isCad): ?>
                                    Téléchargement uniquement &mdash; visionneuse DWG en cours de développement.
                                <?php elseif ($isPdf): ?>
                                    Ouvre le PDF dans un nouvel onglet.
                                <?php endif ?>
                            </span>
                        <?php endif ?>
                    </div>
                </div>
            </li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif /* !$modalOnly */ ?>

<!-- ── Modal viewer (one per page; reused for every plan click) ────── -->
<div class="plan-viewer-modal" id="plan-viewer-modal" aria-hidden="true" role="dialog" aria-modal="true" data-plan-viewer-modal>
    <div class="plan-viewer-modal__bar">
        <span class="plan-viewer-modal__title" data-plan-viewer-title>Plan</span>
        <div class="plan-viewer-modal__controls">
            <button type="button" class="plan-viewer-modal__btn" data-plan-viewer-action="zoom-in" title="Zoom +">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
            </button>
            <button type="button" class="plan-viewer-modal__btn" data-plan-viewer-action="zoom-out" title="Zoom −">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
            </button>
            <button type="button" class="plan-viewer-modal__btn" data-plan-viewer-action="home" title="Ajuster">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9V3h6M21 9V3h-6M3 15v6h6M21 15v6h-6"/></svg>
            </button>
            <button type="button" class="plan-viewer-modal__btn" data-plan-viewer-action="fullscreen" title="Plein écran">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
            </button>
            <button type="button" class="plan-viewer-modal__btn plan-viewer-modal__btn--close" data-plan-viewer-action="close" title="Fermer">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <div class="plan-viewer-modal__canvas" id="plan-viewer-canvas" data-plan-viewer-canvas></div>
    <div class="plan-viewer-modal__hint">
        <kbd>Échap</kbd> Fermer &nbsp;·&nbsp; Molette / pincer pour zoomer
    </div>
</div>

<link rel="stylesheet" href="<?= url('assets/css/plan-viewer.css') ?>?v=1">
<script src="<?= url('assets/js/plan-viewer.js') ?>?v=1" defer></script>
