<?php
/*
  Snippets are a great way to store code snippets for reuse
  or to keep your templates clean.

  The article snippet renders an excerpt of a blog article.

  More about snippets:
  https://getkirby.com/docs/guide/templates/snippets
*/
?>
<article class="note-excerpt">
  <a href="<?= $article->url() ?>">
    <header>
      <figure class="img" style="--w: 16; --h:9">
        <?php if ($cover = $article->cover()): ?>
          <img src="<?= $cover->crop(320, 180)->url() ?>" alt="<?= $cover->alt()->esc() ?>">
        <?php else: ?>
          <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="<?= $article->title()->esc() ?>">
        <?php endif ?>
      </figure>

      <h2 class="note-excerpt-title"><?= $article->title()->esc() ?></h2>
      <time class="note-excerpt-date" datetime="<?= $article->published('c') ?>"><?= $article->published() ?></time>
    </header>
    <?php if (($excerpt ?? true) !== false): ?>
    <div class="note-excerpt-text article-excerpt">
      <?= $article->text()->toBlocks()->excerpt(300) ?>
    </div>
    <?php endif ?>
  </a>
</article>
