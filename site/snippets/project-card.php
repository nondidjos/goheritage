<?php
/*
  Project card snippet — used on the home page and the map panel.
  Expects: $project (a Kirby page of template 'project')
           $lazy (bool) — optional, defer image loading
*/
$lazy = $lazy ?? false;
?>
<article class="project-card">
    <a href="<?= $project->url() ?>" class="project-card__link" aria-label="<?= $project->title()->esc() ?>">
        <div class="project-card__thumb">
            <?php if ($cover = $project->cover()): ?>
                <img src="<?= $cover->resize(800, 600)->url() ?>" alt="<?= $cover->alt()->esc() ?>" <?php if ($lazy): ?>loading="lazy"
                <?php endif ?>
                >
            <?php else: ?>
                <div class="project-card__no-image">No image</div>
            <?php endif ?>
        </div>
        <?php if ($project->location()->isNotEmpty()): ?>
            <p class="project-card__location font-mono">
                <?= $project->location()->esc() ?>
            </p>
        <?php endif ?>
        <h3 class="project-card__title font-gloucester">
            <?= $project->title()->esc() ?>
        </h3>
        <?php if ($project->description()->isNotEmpty()): ?>
            <p class="project-card__desc font-serif">
                <?= $project->description()->esc() ?>
            </p>
        <?php endif ?>
    </a>
</article>