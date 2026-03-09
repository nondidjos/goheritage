<?php
// global header — included in all templates
// conditionally loads map css, model-viewer script, and page-specific assets
$isMapPage     = $page->template()->name() === 'map';
$isProjectPage = $page->template()->name() === 'project';
$cssFiles      = ['assets/css/app.css'];
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
  <?php if ($isProjectPage): ?>
  <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/4.0.0/model-viewer.min.js"></script>
  <?php endif ?>
  <link rel="shortcut icon" type="image/x-icon" href="<?= url('favicon.ico') ?>">
</head>
<body>

<header class="site-header">
  <div class="site-header__inner">

    <!-- logo -->
    <a class="site-logo" href="<?= $site->url() ?>" aria-label="<?= $site->title()->html() ?>">
      <img src="<?= url('assets/icons/logo.svg') ?>" alt="" class="site-logo__icon" width="32" height="32">
      <span class="site-logo__text">GO<em>Héritage</em></span>
    </a>

    <!-- mobile hamburger -->
    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Menu" aria-expanded="false">
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
    </button>

    <!-- navigation -->
    <nav class="site-nav" id="site-nav" aria-label="Navigation principale">
      <?php foreach ($site->children()->listed() as $item): ?>
      <a
        class="site-nav__link"
        href="<?= $item->url() ?>"
        <?php e($item->isOpen(), 'aria-current="page"') ?>
      ><?= $item->title()->html() ?></a>
      <?php endforeach ?>
    </nav>

  </div>
</header>

<?php // breadcrumb — rendered via snippet in each template ?>
<?php if ($isProjectPage): ?>
<?php snippet('breadcrumb', ['items' => [
  ['label' => $page->parent()->title()->value(), 'url' => $page->parent()->url()],
  ['label' => $page->title()->value()],
]]) ?>
<?php endif ?>

<main>
