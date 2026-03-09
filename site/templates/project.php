<?php
// project page — left panel info, right panel viewer
// left sidebar (3 cols): title, metadata table, description
// right area (4 cols): 3D viewer + gallery thumbnails
snippet('header');
?>

<div class="container project-page">
    <div class="grid-7">

        <!-- left: project info -->
        <div class="col-3 project-info">

            <?php snippet('location-tag', ['location' => $page->location()->value(), 'class' => 'project-info__location']) ?>

            <h1 class="project-info__title font-gloucester"><?= $page->title()->esc() ?></h1>

            <!-- metadata table -->
            <dl class="project-meta font-mono">
                <?php if ($page->construction_date()->isNotEmpty()): ?>
                    <div class="project-meta__row">
                        <dt>Date de construction</dt>
                        <dd class="font-serif"><?= $page->construction_date()->esc() ?></dd>
                    </div>
                <?php endif ?>
                <?php if ($page->dimensions()->isNotEmpty()): ?>
                    <div class="project-meta__row">
                        <dt>Dimensions du bâtiment</dt>
                        <dd class="font-serif"><?= $page->dimensions()->esc() ?></dd>
                    </div>
                <?php endif ?>
                <?php if ($page->style()->isNotEmpty()): ?>
                    <div class="project-meta__row">
                        <dt>Style architectural</dt>
                        <dd class="font-serif"><?= $page->style()->esc() ?></dd>
                    </div>
                <?php endif ?>
            </dl>

            <!-- description -->
            <?php if ($page->description()->isNotEmpty()): ?>
                <div class="project-description font-serif">
                    <p><?= $page->description()->esc() ?></p>
                </div>
            <?php endif ?>

            <!-- full content blocks -->
            <?php if ($page->text()->isNotEmpty()): ?>
                <div class="text font-serif">
                    <?= $page->text()->toBlocks() ?>
                </div>
            <?php endif ?>

            <!-- tags -->
            <?php snippet('tags', ['tags' => $page->tags()->split(',')]) ?>
        </div>

        <!-- right: 3D viewer + gallery -->
        <div class="col-4 project-viewer">

            <!-- 3D model viewer -->
            <div class="viewer-container" id="viewer-container">
                <?php if ($page->model_url()->isNotEmpty()): ?>
                    <model-viewer src="<?= $page->model_url()->esc() ?>" alt="Modèle 3D de <?= $page->title()->esc() ?>"
                        auto-rotate camera-controls shadow-intensity="1" style="width:100%; height:100%;"></model-viewer>
                <?php elseif ($cover = $page->cover()): ?>
                    <img src="<?= $cover->crop(1200, 700)->url() ?>" alt="<?= $cover->alt()->esc() ?>">
                <?php else: ?>
                    <div class="viewer-placeholder">
                        <p class="font-mono">Modèle 3D bientôt disponible</p>
                    </div>
                <?php endif ?>
            </div>

            <!-- gallery thumbnails -->
            <?php $gallery = $page->images()->sortBy('sort'); ?>
            <?php if ($gallery->count()): ?>
                <div class="project-gallery">
                    <?php foreach ($gallery->limit(5) as $image): ?>
                        <div class="project-gallery__thumb">
                            <img src="<?= $image->crop(280, 180)->url() ?>"
                                alt="<?= $image->alt()->or($page->title())->esc() ?>" loading="lazy">
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

        </div>

    </div>
</div>

<?php snippet('footer') ?>