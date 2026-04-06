<?php
/**
 * Section block — plain collapsible section.
 * No hotspot tie-in, just a title + rich content.
 */

$title   = $block->section_title()->or('Section')->esc();
$content = $block->section_content()->toBlocks();
?>
<details class="poi-section">
    <summary class="poi-section__summary">
        <svg class="poi-section__chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        <span class="poi-section__title"><?= $title ?></span>
    </summary>
    <div class="poi-section__body">
        <?php if ($content->isNotEmpty()): ?>
            <?= $content ?>
        <?php else: ?>
            <p class="text-faint italic text-sm">Contenu à venir.</p>
        <?php endif ?>
    </div>
</details>
