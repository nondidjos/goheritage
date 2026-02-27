<?php
/*
  blog listing page — notes.php
  layout: flagship article (col 1-5) + 4 side cards stacked (col 6-7)
*/
$articles = $page->children()->listed()->sortBy('date', 'desc');
$flagship = $articles->first();
$secondary = $articles->offset(1)->limit(4);
?>
<?php snippet('header') ?>

<div class="container">

  <div class="blog-header">
    <h1 class="blog-page-title font-gloucester"><?= $page->title()->esc() ?></h1>
    <p class="font-serif color-mid">
      Dispatches from the field — documenting heritage, methodology, and conservation.
    </p>
  </div>

  <?php if ($flagship): ?>
    <div class="blog-listing">
      <div class="grid-7">

        <?php /* flagship article */ ?>
        <article class="col-5 blog-card">
          <?php if ($cover = $flagship->cover()): ?>
            <a href="<?= $flagship->url() ?>" class="blog-card__thumb"
              style="display:block; aspect-ratio:16/9; overflow:hidden; background:var(--color-surface); margin-bottom:var(--baseline);">
              <img src="<?= $cover->resize(1200, 675)->url() ?>" alt="<?= $cover->alt()->esc() ?>">
            </a>
          <?php endif ?>
          <p class="blog-card__date font-mono"><?= $flagship->date()->toDate('d F Y') ?></p>
          <h2 class="blog-card__title blog-card__title--flagship font-sans">
            <a href="<?= $flagship->url() ?>"><?= $flagship->title()->esc() ?></a>
          </h2>
          <?php if ($flagship->subheading()->isNotEmpty()): ?>
            <p class="blog-card__excerpt font-serif" style="margin-top:var(--baseline);">
              <?= $flagship->subheading()->esc() ?>
            </p>
          <?php else: ?>
            <p class="blog-card__excerpt font-serif" style="margin-top:var(--baseline);">
              <?= $flagship->text()->toBlocks()->excerpt(200) ?>
            </p>
          <?php endif ?>
          <div style="margin-top:var(--baseline-2x);">
            <a href="<?= $flagship->url() ?>" class="btn">Read Article</a>
          </div>
        </article>

        <?php /* 4 secondary cards */ ?>
        <div class="col-2">
          <div class="blog-side-cards">
            <?php foreach ($secondary as $note): ?>
              <article class="blog-card">
                <?php if ($cover = $note->cover()): ?>
                  <a href="<?= $note->url() ?>" class="blog-card__thumb"
                    style="display:block; aspect-ratio:16/9; overflow:hidden; background:var(--color-surface); margin-bottom:var(--baseline-half);">
                    <img src="<?= $cover->resize(400, 225)->url() ?>" alt="<?= $cover->alt()->esc() ?>" loading="lazy">
                  </a>
                <?php endif ?>
                <p class="blog-card__date font-mono"><?= $note->date()->toDate('d M Y') ?></p>
                <h3 class="blog-card__title font-sans">
                  <a href="<?= $note->url() ?>"><?= $note->title()->esc() ?></a>
                </h3>
              </article>
            <?php endforeach ?>
          </div>
        </div>

      </div>

      <?php /* additional articles */ ?>
      <?php $rest = $articles->offset(5); ?>
      <?php if ($rest->count()): ?>
        <hr class="divider" style="margin-top:var(--baseline-4x);">
        <p class="section-label font-mono" style="margin-top:var(--baseline-3x);">More Articles</p>
        <div class="grid-7" style="margin-top:var(--baseline-2x);">
          <?php foreach ($rest as $note): ?>
            <article class="col-2 blog-card">
              <?php if ($cover = $note->cover()): ?>
                <a href="<?= $note->url() ?>" class="blog-card__thumb"
                  style="display:block; aspect-ratio:16/9; overflow:hidden; background:var(--color-surface); margin-bottom:var(--baseline);">
                  <img src="<?= $cover->resize(600, 338)->url() ?>" alt="<?= $cover->alt()->esc() ?>" loading="lazy">
                </a>
              <?php endif ?>
              <p class="blog-card__date font-mono"><?= $note->date()->toDate('d M Y') ?></p>
              <h3 class="blog-card__title font-sans"><a href="<?= $note->url() ?>"><?= $note->title()->esc() ?></a></h3>
            </article>
          <?php endforeach ?>
        </div>
      <?php endif ?>

    </div>
  <?php else: ?>
    <p class="font-serif color-mid" style="padding: var(--baseline-4x) 0;">No articles published yet.</p>
  <?php endif ?>

</div>

<?php snippet('footer') ?>