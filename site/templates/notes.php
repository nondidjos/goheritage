<?php
/*
  Blog listing page
  layout: flagship article with overlay on left (5 cols)
  grid of older articles below (2 cols each)
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
      Dépêches du terrain — documenter le patrimoine, la méthodologie et la conservation.
    </p>
  </div>

  <?php if ($flagship): ?>
    <div class="blog-listing">
      <div class="grid-7">

        <?php /* flagship article with overlay */ ?>
        <article class="col-5 blog-card blog-card--flagship">
          <?php if ($cover = $flagship->cover()): ?>
            <a href="<?= $flagship->url() ?>" class="blog-card__cover">
              <img src="<?= $cover->resize(1200, 675)->url() ?>" alt="<?= $cover->alt()->esc() ?>">
              <div class="blog-card__overlay">
                <p class="blog-card__overlay-category font-mono">
                  <?= $flagship->tags()->isNotEmpty() ? $flagship->tags()->split(',')[0] : 'Article' ?>
                </p>
                <h2 class="blog-card__overlay-title font-serif"><?= $flagship->title()->esc() ?></h2>
                <p class="blog-card__overlay-excerpt font-serif">
                  <?php if ($flagship->subheading()->isNotEmpty()): ?>
                    <?= $flagship->subheading()->esc() ?>
                  <?php else: ?>
                    <?= $flagship->text()->toBlocks()->excerpt(120) ?>
                  <?php endif ?>
                </p>
              </div>
            </a>
          <?php endif ?>
        </article>

        <?php /* side cards */ ?>
        <div class="col-2">
          <div class="blog-side-cards">
            <?php foreach ($secondary as $note): ?>
              <article class="blog-card blog-card--small">
                <p class="blog-card__date font-mono"><?= $note->date()->toDate('d M Y') ?></p>
                <h3 class="blog-card__title font-sans">
                  <a href="<?= $note->url() ?>"><?= $note->title()->esc() ?></a>
                </h3>
                <?php if ($note->subheading()->isNotEmpty()): ?>
                  <p class="blog-card__excerpt font-serif"><?= $note->subheading()->excerpt(80) ?></p>
                <?php endif ?>
              </article>
            <?php endforeach ?>
          </div>
        </div>

      </div>

      <?php /* additional articles */ ?>
      <?php $rest = $articles->offset(5); ?>
      <?php if ($rest->count()): ?>
        <hr class="divider" style="margin-top:var(--baseline-4x);">
        <p class="section-label font-mono" style="margin-top:var(--baseline-3x);">Plus d'articles</p>
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
    <p class="font-serif color-mid" style="padding: var(--baseline-4x) 0;">Aucun article publié pour le moment.</p>
  <?php endif ?>

</div>

<?php snippet('footer') ?>