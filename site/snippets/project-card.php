<?php
/*
  Project card snippet — used on the home page and the map panel.
  Expects: $project (a Kirby page of template 'project')
           $lazy (bool) — optional, defer image loading
*/
$lazy = $lazy ?? false;
$cover = $project->cover();
?>
<article class="project-card">
    <a href="<?= $project->url() ?>" class="project-card__link" aria-label="<?= $project->title()->esc() ?>">
        <div class="project-card__thumb">
            <?php if ($cover): ?>
                <img src="<?= $cover->resize(800, 600)->url() ?>" alt="<?= $cover->alt()->esc() ?>" <?php if ($lazy): ?>loading="lazy" <?php endif ?>>
            <?php else: ?>
                <div class="project-card__placeholder"></div>
            <?php endif ?>
        </div>
        <?php snippet('location-tag', ['location' => $project->location()->value(), 'class' => 'project-card__location']) ?>
        <h3 class="project-card__title"><?= $project->title()->esc() ?></h3>
        <?php if ($project->description()->isNotEmpty()): ?>
            <p class="project-card__desc"><?= $project->description()->esc() ?></p>
        <?php endif ?>
    </a>
</article>