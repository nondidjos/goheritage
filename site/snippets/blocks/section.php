<?php
/**
 * Section block — collapsible section with optional 3D hotspot link.
 *
 * When hotspot_id is set:
 *   Desktop: expanding activates the hotspot in the 3D viewer.
 *   Mobile:  body shown as popup when the hotspot label is tapped.
 * When empty: plain collapsible section, no viewer interaction.
 */

$title     = $block->section_title()->or('Section')->esc();
$hotspotId = $block->hotspot_id()->value();
$content   = $block->section_content()->toBlocks();

$dataAttr  = $hotspotId ? ' data-hotspot="' . htmlspecialchars($hotspotId) . '"' : '';
?>
<details class="poi-section"<?= $dataAttr ?>>
    <summary class="poi-section__summary">
        <svg class="poi-section__chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        <span class="poi-section__title"><?= $title ?></span>
        <?php if ($hotspotId): ?>
            <span class="poi-section__hotspot-badge" title="Lié au viewer 3D" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="4"/><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z" opacity=".35"/></svg>
            </span>
        <?php endif ?>
    </summary>
    <div class="poi-section__body">
        <?php if ($content->isNotEmpty()): ?>
            <?= $content ?>
        <?php else: ?>
            <p class="poi-section__empty">Contenu à venir.</p>
        <?php endif ?>
    </div>
</details>
