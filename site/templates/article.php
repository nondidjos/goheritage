<?php snippet('header') ?>

<div class="py-12">
  <div class="col-7">
    <p class="byline mb-3"><?= $page->date()->toDate('d F Y') ?></p>
    <h1 class="font-sans leading-tight mb-4 text-[clamp(2rem,4vw,3.5rem)]"><?= $page->title()->esc() ?></h1>
    <?php if ($page->subheading()->isNotEmpty()): ?>
      <p class="font-serif text-xl text-mid"><?= $page->subheading()->esc() ?></p>
    <?php endif ?>
  </div>
</div>

<?php $cover = $page->cover(); ?>
<div class="col-7 aspect-[16/7] overflow-hidden my-8">
  <?php if ($cover): ?>
    <img src="<?= $cover->crop(1600, 800)->url() ?>" alt="<?= $cover->alt()->esc() ?>"
      class="w-full h-full object-cover">
  <?php else: ?>
    <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="<?= $page->title()->esc() ?>"
      class="w-full h-full object-cover">
  <?php endif ?>
</div>

<div class="py-16">
  <div class="col-5">
    <div class="text font-serif">
      <?= $page->text()->toBlocks() ?>
    </div>

    <?php if (!empty($tags)): ?>
      <ul class="flex flex-wrap gap-2 list-none mt-12">
        <?php foreach ($tags as $tag): ?>
          <li>
            <a class="tag border border-border text-mid bg-surface no-underline hover:border-mid hover:text-ink hover:bg-surface/80 hover:no-underline transition-all duration-150"
              href="<?= $page->parent()->url(['params' => ['tag' => $tag]]) ?>">
              <?= esc($tag) ?>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>

    <?php snippet('prevnext') ?>
  </div>
</div>

<?php snippet('footer') ?>