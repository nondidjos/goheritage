<?php snippet('header') ?>

<div class="py-6 md:py-12">
  <div class="col-2 hidden md:block"></div>
  <div class="col-3">
    <div class="text-center">
      <?php if ($author = $page->author()->toUser()): ?>
        <p class="byline mb-3"><?= $page->date()->toDate('d F Y') ?> by <span class="underline"><?= $author->name()->esc() ?></span></p>
      <?php else: ?>
        <p class="byline mb-3"><?= $page->date()->toDate('d F Y') ?></p>
      <?php endif ?>
      <h1 class="font-sans leading-tight mb-4 text-[clamp(2rem,4vw,3.5rem)]"><?= $page->title()->esc() ?></h1>
    </div>
    <?php if ($page->subheading()->isNotEmpty()): ?>
      <p class="font-sans text-[15px] text-mid"><?= $page->subheading()->esc() ?></p>
    <?php endif ?>
  </div>
</div>

<?php $cover = $page->cover()->toFile(); ?>
<div class="py-3 md:py-8">
  <div class="col-1 home-spacer"></div>
  <div class="col-5 aspect-16/7 overflow-hidden">
    <?php if ($cover): ?>
      <img src="<?= $cover->crop(1200, 525)->url() ?>" alt="<?= $cover->alt()->esc() ?>"
        class="w-full h-full object-cover">
    <?php else: ?>
      <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="<?= $page->title()->esc() ?>"
        class="w-full h-full object-cover">
    <?php endif ?>
  </div>
</div>

<div class="pt-8 md:pt-12 pb-12 md:pb-20">
  <div class="col-2 hidden md:block"></div>
  <div class="col-3">
    <div class="text font-serif">
      <?= $page->text()->toBlocks() ?>
    </div>

    <?php if (!empty($tags)): ?>
      <ul class="flex flex-wrap gap-2 list-none mt-12">
        <?php foreach ($tags as $tag): ?>
          <li>
            <a class="tag border border-border text-mid bg-surface no-underline hover:border-mid hover:text-ink hover:bg-surface/80 hover:no-underline transition-all duration-150"
              href="<?= $page->parent()->url() ?>?tag=<?= urlencode(trim($tag)) ?>">
              <?= esc($tag) ?>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </div>
</div>

<div class="pb-12 md:pb-20">
  <div class="col-1 home-spacer"></div>
  <div class="col-5">
    <?php snippet('prevnext') ?>
  </div>
</div>

<?php snippet('footer') ?>
