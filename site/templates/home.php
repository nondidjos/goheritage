<?php
// home page — hero + recent scans + latest blog post
snippet('header');
?>

<section class="hero">
  <div class="container">
    <p class="hero__label">Conservation du patrimoine</p>
    <h1 class="hero__title">
      <?= $page->headline()->or($site->title())->esc() ?>
    </h1>
    <p class="hero__sub">
      <?= $page->subheadline()->esc() ?>
    </p>
    <div class="hero__actions">
      <a href="<?= url('map') ?>" class="btn btn--filled">Explorer la carte</a>
      <a href="<?= url('notes') ?>" class="btn">Lire le blog</a>
    </div>
  </div>
</section>

<?php
$mapPage = page('map');
$projects = $mapPage ? $mapPage->children()->listed()->sortBy('date', 'desc')->limit(2) : null;
?>

<?php if ($projects && $projects->count()): ?>
  <section class="home-section">
    <div class="container">
      <div class="home-section__header">
        <p class="section-label">Scans récents</p>
        <a href="<?= url('map') ?>" class="section-link">Tous nos projets <span class="arrow">→</span></a>
      </div>
      <div class="grid-7">
        <?php foreach ($projects as $project): ?>
          <div class="col-3">
            <?php snippet('project-card', ['project' => $project, 'lazy' => true]) ?>
          </div>
        <?php endforeach ?>
        <div class="col-1"></div>
      </div>
    </div>
  </section>
<?php endif ?>

<?php
$blogPage = page('notes');
$latestPost = $blogPage ? $blogPage->children()->listed()->sortBy('date', 'desc')->first() : null;
?>

<?php if ($latestPost): ?>
  <section class="home-section">
    <div class="container">
      <p class="section-label">Dernières nouvelles</p>
      <div class="grid-7">
        <?php if ($cover = $latestPost->cover()): ?>
          <div class="col-3">
            <a href="<?= $latestPost->url() ?>" class="blog-card">
              <div class="blog-card__thumb">
                <img src="<?= $cover->resize(800, 450)->url() ?>" alt="<?= $cover->alt()->esc() ?>" loading="lazy">
              </div>
            </a>
          </div>
        <?php endif ?>
        <div class="<?= $latestPost->cover() ? 'col-4' : 'col-7' ?>">
          <p class="blog-card__date">
            <?= $latestPost->date()->toDate('d F Y') ?>
          </p>
          <h2 class="blog-card__title--flagship">
            <a href="<?= $latestPost->url() ?>"><?= $latestPost->title()->esc() ?></a>
          </h2>
          <p class="blog-card__excerpt" style="margin-top: 0.5rem;">
            <?= $latestPost->text()->toBlocks()->excerpt(200) ?>
          </p>
          <div style="margin-top: 1rem;">
            <a href="<?= $latestPost->url() ?>" class="btn">Lire l'article</a>
          </div>
        </div>
      </div>
    </div>
  </section>
<?php endif ?>

<?php snippet('footer') ?>