<?php
/**
 * ?pointcloud=1 embed pane: renders ONLY the point-cloud viewer for a
 * project (an uploaded PLY/PCD via pointcloud-viewer.js, a streamed COPC
 * via copc-viewer.js, an external Potree/web viewer, or an explanation of
 * what's missing). Used as the iframe source for the main page's
 * point-cloud switcher pane (data-pc-src) and for the panel's own "Nuage
 * de points" tab preview.
 *
 * Expects $page, $pcDots, $pcExternal, $pcCopc, $pcInline, $pcOther —
 * all resolved once by site/controllers/project.php so this pane and the
 * main page's point-cloud pane can never disagree about what's available.
 */
?>
<style>
  .pc-stage { position: fixed; inset: 0; background: #1a1a1a; }
  .pc-stage iframe, #gh-pointcloud-viewer, #gh-copc-viewer { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
  .pc-msg { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.9rem; padding: 2rem; text-align: center; color: rgba(255,255,255,0.6); font-family: var(--font-mono, 'IBM Plex Mono', ui-monospace, monospace); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.1em; line-height: 1.6; }
  .pc-msg strong { color: #fff; }
  .pc-msg__ico { width: 42px; height: 42px; opacity: 0.5; }
</style>
<div class="pc-stage">
  <?php if ($pcExternal): ?>
    <iframe src="<?= esc($pcExternal) ?>" allow="xr-spatial-tracking; fullscreen" allowfullscreen></iframe>
  <?php elseif ($pcCopc): ?>
    <div id="gh-copc-viewer" data-src="<?= esc($page->assetUrl($pcCopc)) ?>" data-wasm="<?= esc(url('assets/wasm/laz-perf.wasm')) ?>"></div>
  <?php elseif ($pcInline): ?>
    <div id="gh-pointcloud-viewer" data-src="<?= esc($page->assetUrl($pcInline)) ?>" data-format="<?= esc($pcInline->extension()) ?>"></div>
  <?php elseif ($pcOther): ?>
    <div class="pc-msg">
      <svg class="pc-msg__ico" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true"><?= $pcDots ?><text x="20" y="8" font-size="9" font-family="monospace" font-weight="700" fill="#fff">?</text></svg>
      <span>Format <strong><?= strtoupper(esc($pcOther->extension())) ?></strong> non pris en charge</span>
    </div>
  <?php else: ?>
    <div class="pc-msg">
      <svg class="pc-msg__ico" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true"><?= $pcDots ?><line x1="3.5" y1="20.5" x2="20.5" y2="3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      <span>Aucun nuage de points</span>
    </div>
  <?php endif ?>
</div>
