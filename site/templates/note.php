<?php
/*
  single note / blog article template
  title: DM Sans, body: IBM Plex Serif
  tags from the note controller
*/
?>
<?php snippet('header') ?>

<div class="container">

  <div class="article-header">
    <div class="grid-7">
      <div class="col-7">
        <p class="article-date font-mono"><?= $page->date()->toDate('d F Y') ?></p>
        <h1 class="article-title font-sans"><?= $page->title()->esc() ?></h1>
        <?php if ($page->subheading()->isNotEmpty()): ?>
          <p class="article-sub font-serif"><?= $page->subheading()->esc() ?></p>
        <?php endif ?>
      </div>
    </div>
  </div>

  <?php if ($cover = $page->cover()): ?>
    <div class="article-cover">
      <img src="<?= $cover->crop(1600, 800)->url() ?>" alt="<?= $cover->alt()->esc() ?>">
    </div>
  <?php endif ?>

  <div class="article-body">
    <div class="grid-7">
      <div class="col-5">
        <div class="text font-serif">
          <?= $page->text()->toBlocks() ?>
        </div>

        <?php if (!empty($tags)): ?>
          <ul class="article-tags" style="margin-top: var(--baseline-2x);">
            <?php foreach ($tags as $tag): ?>
              <li>
                <a class="article-tag font-mono" href="<?= $page->parent()->url(['params' => ['tag' => $tag]]) ?>">
                  <?= esc($tag) ?>
                </a>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>

        <?php snippet('prevnext') ?>
      </div>
    </div>
  </div>

</div>

<?php snippet('footer') ?>