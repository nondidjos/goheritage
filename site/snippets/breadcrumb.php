<?php
$items = $items ?? [];
?>
<nav class="breadcrumb" aria-label="Fil d'Ariane">
    <div class="breadcrumb__inner">
        <?php foreach ($items as $i => $item): ?>
            <span class="breadcrumb__sep">></span>
            <?php if (!empty($item['url']) && $i < count($items) - 1): ?>
                <a href="<?= $item['url'] ?>" class="breadcrumb__parent"><?= esc($item['label']) ?></a>
            <?php else: ?>
                <span class="breadcrumb__current"><?= esc($item['label']) ?></span>
            <?php endif ?>
        <?php endforeach ?>
    </div>
</nav>