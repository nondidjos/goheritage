<?php
// global header — included in all templates
$isMapPage     = $page->template()->name() === 'map';
$isProjectPage = $page->template()->name() === 'project';
$cssFiles      = ['assets/css/app.css', 'assets/css/custom.css'];
if ($isMapPage) {
    $cssFiles[] = 'assets/css/map.css';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= $page->title()->html() ?> — <?= $site->title()->html() ?></title>
  <meta name="description" content="<?= $page->description()->or($site->description())->html() ?>">
  <?= css($cssFiles) ?>
  <?php if ($isMapPage): ?>
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.css">
  <?php endif ?>
  <?php
  // Only load the Three.js viewer when there is no external viewer URL set.
  // If viewer_url is filled in, the project page renders an iframe instead —
  // loading Three.js would be wasteful and serves no purpose.
  $needsThreeJs = $isProjectPage && $page->viewer_url()->isEmpty();
  if ($isProjectPage): ?>
  <?= css('assets/css/lightbox.css') ?>
  <?php endif ?>
  <?php if ($needsThreeJs): ?>
  <?= css('assets/css/viewer.css') ?>
  <script type="importmap">
  {
    "imports": {
      "three": "<?= url('node_modules/three/build/three.module.min.js') ?>",
      "three/addons/": "<?= url('node_modules/three/examples/jsm/') ?>"
    }
  }
  </script>
  <script type="module" src="<?= url('assets/js/viewer.js') ?>"></script>
  <?php endif ?>
  <link rel="shortcut icon" type="image/x-icon" href="<?= url('favicon.ico') ?>">
</head>
<body>

<header class="sticky top-0 z-50 bg-white">
  <div class="grid-7 items-center py-4">

    <!-- logo -->
    <div class="col-2">
      <a class="no-underline hover:no-underline" href="<?= $site->url() ?>" aria-label="<?= $site->title()->html() ?>">
        <img src="<?= url('assets/logos/goheritage.svg') ?>" alt="GoHéritage" class="h-7 w-auto">
      </a>
    </div>

    <!-- spacer -->
    <div class="col-3"></div>

    <!-- mobile hamburger -->
    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Menu" aria-expanded="false">
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
    </button>

    <!-- navigation — right-aligned, 2 cols spaced out -->
    <nav class="site-nav col-2 flex w-full items-center justify-between" id="site-nav" aria-label="Navigation principale">
      <?php foreach ($site->children()->listed() as $item): ?>
      <a
        class="font-sans text-sm uppercase tracking-wider text-ink no-underline transition-colors duration-150 hover:underline hover:text-ink"
        href="<?= $item->url() ?>"
        <?php e($item->isOpen(), 'aria-current="page"') ?>
      ><?= $item->title()->html() ?></a>
      <?php endforeach ?>
    </nav>

  </div>
</header>

<?php
$tplName = $page->template()->name();
if ($tplName === 'project'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->parent()->title()->value(), 'url' => $page->parent()->url()],
  ['label' => $page->title()->value()],
]]) ?>
<?php elseif ($tplName === 'article'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->parent()->title()->value(), 'url' => $page->parent()->url()],
  ['label' => $page->title()->value()],
]]) ?>
<?php elseif ($tplName === 'blog'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->title()->value()],
]]) ?>
<?php elseif ($tplName === 'map'): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->title()->value()],
]]) ?>
<?php endif ?>

<main<?= $isMapPage ? ' id="map-main"' : '' ?>>
