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
  // Only load a 3D/pano viewer when no external viewer URL is set.
  // Decide which of the two bundled viewers is needed on the project page.
  $viewerMode = null;
  if ($isProjectPage && $page->viewer_url()->isEmpty()) {
      $panoFilesCount = $page->pano_files()->toFiles()->count();
      if ($panoFilesCount === 0) {
          $panoFilesCount = $page->images()->template('panorama')->count();
      }
      $hasPanoJson    = ($page->file('pano-hotspots.json') ?? $page->pano_hotspots_json()->toFile()) !== null;
      $hasPanoAssets  = ($panoFilesCount > 0 || $hasPanoJson);
      $hasModelAssets = $page->file('exterior.obj') || $page->file('exterior.glb')
                     || $page->file('interior.obj') || $page->file('interior.glb')
                     || $page->model_obj()->toFile() || $page->model_obj_interior()->toFile();
      $pref = $page->viewer_preference()->or('auto')->value();
      if ($pref === 'model')         $viewerMode = $hasModelAssets ? 'model' : ($hasPanoAssets ? 'pano' : null);
      elseif ($pref === 'panorama')  $viewerMode = $hasPanoAssets  ? 'pano'  : ($hasModelAssets ? 'model' : null);
      else                           $viewerMode = $hasPanoAssets  ? 'pano'  : ($hasModelAssets ? 'model' : null);
  }
  if ($isProjectPage): ?>
  <?= css('assets/css/lightbox.css') ?>
  <?= css('assets/css/viewer.css') ?>
  <?php endif ?>
  <?php if ($viewerMode !== null): ?>
  <script type="importmap">
  {
    "imports": {
      "three": "<?= url('node_modules/three/build/three.module.min.js') ?>",
      "three/addons/": "<?= url('node_modules/three/examples/jsm/') ?>"
    }
  }
  </script>
  <?php endif ?>
  <?php if ($viewerMode === 'model'): ?>
  <script type="module" src="<?= url('assets/js/viewer.js') ?>"></script>
  <?php elseif ($viewerMode === 'pano'): ?>
  <?= css(panoviewerAsset('panoviewer.css')) ?>
  <script type="module">
    // Plugin: site/plugins/panoviewer/. Bridge module reads data-* attrs on
    // #pano-viewer + boots a PanoViewer instance. Heavy lifting lives in
    // the plugin so this template stays tiny.
    import { boot } from '<?= panoviewerAsset('goheritage-bridge.js') ?>';
    boot(document.getElementById('pano-viewer'));
  </script>
  <?php endif ?>
  <link rel="shortcut icon" type="image/x-icon" href="<?= url('favicon.ico') ?>">
</head>
<body>

<header class="sticky top-0 z-50 bg-white">
  <div class="grid-7 items-center py-4">

    <!-- logo -->
    <div class="col-2">
      <a class="no-underline hover:no-underline" href="<?= $site->url() ?>" aria-label="<?= $site->title()->html() ?>">
        <img src="<?= url('assets/logos/goheritage.svg') ?>" alt="GoHéritage" class="h-7 w-auto rounded-none">
      </a>
    </div>

    <!-- spacer (hidden on mobile, header becomes flex) -->
    <div class="col-3 hidden md:block"></div>

    <!-- mobile hamburger -->
    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Menu" aria-expanded="false">
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
      <span class="hamburger-line"></span>
    </button>

    <!-- navigation — right-aligned, 2 cols spaced out -->
    <nav class="site-nav col-2 flex w-full items-center justify-between gap-4" id="site-nav" aria-label="Navigation principale">
      <?php foreach ($site->children()->listed()->not($site->homePage()) as $item): ?>
      <a
        class="font-sans text-sm uppercase tracking-wider text-ink no-underline transition-colors duration-150 hover:underline hover:text-ink"
        href="<?= $item->url() ?>"
        <?php e($item->isOpen(), 'aria-current="page"') ?>
      ><?= $item->title()->html() ?></a>
      <?php endforeach ?>

      <!-- cart button — placeholder (no backend yet) -->
      <button
        type="button"
        class="cart-btn relative inline-flex items-center justify-center w-9 h-9 rounded-full border border-border text-ink hover:bg-surface transition-colors duration-150"
        aria-label="Panier"
        data-cart-count="0">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="9" cy="21" r="1"></circle>
          <circle cx="20" cy="21" r="1"></circle>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
        <span class="cart-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full text-white text-[10px] font-mono leading-[18px] text-center hidden" style="background-color: var(--color-accent);">0</span>
      </button>

      <!-- mobile close button -->
      <button class="mobile-menu-close" id="mobile-menu-close" aria-label="Fermer le menu">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
        <span>Fermer</span>
      </button>
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
