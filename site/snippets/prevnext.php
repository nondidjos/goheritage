<?php
// prev/next article links at the bottom of an article
?>
<nav class="mt-16 pt-10 border-t border-border">
  <p class="font-mono text-xs uppercase tracking-wider text-faint mb-6">Continuer la lecture</p>
  <div class="grid-7">
    <?php if ($prev = $page->prevListed()): ?>
      <div class="col-3">
        <a href="<?= $prev->url() ?>" class="prevnext-link">
          <p class="font-mono text-xs text-faint mb-1"><?= $prev->date()->toDate('d M Y') ?></p>
          <h3 class="font-sans font-medium text-ink text-base leading-snug prevnext-link__title"><?= $prev->title()->esc() ?></h3>
        </a>
      </div>
    <?php endif ?>
    <?php if ($next = $page->nextListed()): ?>
      <div class="col-3">
        <a href="<?= $next->url() ?>" class="prevnext-link">
          <p class="font-mono text-xs text-faint mb-1"><?= $next->date()->toDate('d M Y') ?></p>
          <h3 class="font-sans font-medium text-ink text-base leading-snug prevnext-link__title"><?= $next->title()->esc() ?></h3>
        </a>
      </div>
    <?php endif ?>
  </div>
</nav>
