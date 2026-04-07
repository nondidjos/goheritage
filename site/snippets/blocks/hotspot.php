<?php
/**
 * Hotspot block snippet
 *
 * Renders an inline button that activates a named 3D hotspot in the viewer.
 * Dispatches a "goheritage:activate" CustomEvent on #viewer-3d.
 * Works only when the Three.js viewer is present on the same page.
 */

$hotspotId = $block->hotspot_id()->value();
$label     = $block->label()->isNotEmpty()
    ? $block->label()->esc()
    : htmlspecialchars($hotspotId);

if (!$hotspotId) return;
?>
<button
  type="button"
  class="hotspot-link"
  data-hotspot-id="<?= htmlspecialchars($hotspotId) ?>"
  aria-label="Voir le point d'intérêt dans le modèle 3D"
>
  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    <circle cx="12" cy="12" r="5"/>
    <path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z" opacity=".4"/>
  </svg>
  <?= $label ?>
</button>
