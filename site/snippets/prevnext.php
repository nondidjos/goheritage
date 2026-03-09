<?php
// prev/next article links at the bottom of a note
?>
<nav class="blog-prevnext" style="margin-top: var(--baseline-4x);">
  <h2 class="section-label font-mono">Continuer la lecture</h2>
  <div class="grid-7" style="margin-top: var(--baseline);">
    <?php if ($prev = $page->prevListed()): ?>
      <div class="col-3">
        <a href="<?= $prev->url() ?>" class="blog-card">
          <p class="blog-card__date font-mono"><?= $prev->date()->toDate('d M Y') ?></p>
          <h3 class="blog-card__title font-sans"><?= $prev->title()->esc() ?></h3>
        </a>
      </div>
    <?php endif ?>
    <?php if ($next = $page->nextListed()): ?>
      <div class="col-3">
        <a href="<?= $next->url() ?>" class="blog-card">
          <p class="blog-card__date font-mono"><?= $next->date()->toDate('d M Y') ?></p>
          <h3 class="blog-card__title font-sans"><?= $next->title()->esc() ?></h3>
        </a>
      </div>
    <?php endif ?>
  </div>
</nav>