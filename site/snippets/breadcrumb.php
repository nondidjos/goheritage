<?php
// breadcrumbs thingy. last item is current page
// pass items as [['label' => '...', 'url' => '...']]
$items = $items ?? [];
?>
<nav class="breadcrumb" aria-label="Fil d'Ariane">
    <div class="breadcrumb__inner">
        <a href="<?= $site->url() ?>">Accueil</a>
        <?php foreach ($items as $i => $item): ?>
            <span class="breadcrumb__sep">›</span>
            <?php if ($i < count($items) - 1): ?>
                <a href="<?= $item['url'] ?>">
                    <?= Str::esc($item['label']) ?>
                </a>
            <?php else: ?>
                <span class="breadcrumb__current">
                    <?= Str::esc($item['label']) ?>
                </span>
            <?php endif ?>
        <?php endforeach ?>
    </div>
</nav>