<?php
// global header — included in all templates
$isMapPage     = $page->template()->name() === 'map';
$isProjectPage = $page->template()->name() === 'project';
$isEmbedded    = !empty(get('embed'));
$cssFiles      = ['assets/css/app.css', 'assets/css/custom.css'];
if ($isMapPage) {
    $cssFiles[] = 'assets/css/map.css';
}
// Bump on any CSS/JS asset change so mobile browsers re-fetch (Kirby's
// css()/js() helpers add no cache-busting and phones cache hard).
$ghAssetVer = '3';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= $page->title()->html() ?> — <?= $site->title()->html() ?></title>
  <meta name="description" content="<?= $page->description()->or($site->description())->html() ?>">
  <?php if ($isEmbedded): ?>
  <!-- Embed mode: preload critical fonts so they paint without a FOUC when
       loaded cross-origin from an iframe. crossorigin is required because the
       site sends Access-Control-Allow-Origin for /assets/fonts/ in .htaccess. -->
  <link rel="preload" as="font" type="font/woff" crossorigin href="<?= url('assets/fonts/DMSans-Medium.woff') ?>">
  <link rel="preload" as="font" type="font/woff" crossorigin href="<?= url('assets/fonts/IBMPlexMono-Medium.woff') ?>">
  <link rel="preload" as="font" type="font/woff" crossorigin href="<?= url('assets/fonts/IBMPlexSerif-Regular-Latin1.woff') ?>">
  <!-- Inline a tiny first-paint stylesheet so the iframe shows a neutral
       background + spinner immediately, before app.css has loaded. -->
  <style>
    html, body { background: #faf9f7; color: #1a1a1a; margin: 0; }
    #embed-loader {
      position: fixed; inset: 0;
      display: flex; align-items: center; justify-content: center;
      background: #faf9f7;
      z-index: 9999;
      transition: opacity .35s ease;
    }
    #embed-loader.is-hidden { opacity: 0; pointer-events: none; }
    #embed-loader::after {
      content: '';
      width: 28px; height: 28px;
      border: 2px solid rgba(0,0,0,.12);
      border-top-color: rgba(0,0,0,.55);
      border-radius: 50%;
      animation: embed-spin .9s linear infinite;
    }
    @keyframes embed-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
      #embed-loader::after { animation: none; }
    }
  </style>
  <?php endif ?>
  <?php foreach ($cssFiles as $ghCss): ?>
  <link rel="stylesheet" href="<?= url($ghCss) ?>?v=<?= $ghAssetVer ?>">
  <?php endforeach ?>
  <?php if ($isMapPage): ?>
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.css">
  <?php endif ?>
  <?php
  // Only load the Three.js *scripts* when there is no external viewer URL.
  // The styles in viewer.css (chips, panes, spec card, poi sections…) apply
  // to every project page including iframe-only ones, so we always load the
  // stylesheet — just not the 30+ KB of three.js when it isn't needed.
  // The point-cloud preview (?pointcloud=1) always needs three.js — even when
  // a 3D external viewer URL is set — since it renders an uploaded PLY/PCD.
  $isPointcloud = $isProjectPage && !empty(get('pointcloud'));
  $needsThreeJs = $isProjectPage && ($isPointcloud || $page->viewer_url()->isEmpty());
  if ($isProjectPage): ?>
  <?= css('assets/css/lightbox.css') ?>
  <link rel="stylesheet" href="<?= url('assets/css/viewer.css') ?>?v=<?= $ghAssetVer ?>">
  <?php endif ?>
  <?php if ($needsThreeJs): ?>
  <?php
    // Three.js is loaded from a CDN in production (so we don't have to ship
    // node_modules to the server) and from local node_modules in development
    // (so it works fully offline). Pin the exact version that's in
    // package.json so we don't get surprise breakage on upstream releases.
    $threeVersion = '0.183.2';
    $threeHost    = $kirby->environment()->host() ?? '';
    $isLocalDev   = $threeHost === 'localhost'
                 || str_starts_with($threeHost, '127.')
                 || str_starts_with($threeHost, '192.168.')
                 || str_ends_with($threeHost, '.test')
                 || str_ends_with($threeHost, '.local');
    if ($isLocalDev) {
        $threeBase = url('node_modules/three');
    } else {
        $threeBase = 'https://unpkg.com/three@' . $threeVersion;
    }
  ?>
  <script type="importmap">
  {
    "imports": {
      "three": "<?= $threeBase ?>/build/three.module.min.js",
      "three/addons/": "<?= $threeBase ?>/examples/jsm/"
    }
  }
  </script>
  <?php if ($isPointcloud): ?>
  <script type="module" src="<?= url('assets/js/pointcloud-viewer.js') ?>?v=3"></script>
  <?php else: ?>
  <script type="module" src="<?= url('assets/js/viewer.js') ?>?v=3"></script>
  <?php endif ?>
  <?php endif ?>
  <link rel="shortcut icon" type="image/x-icon" href="<?= url('favicon.ico') ?>">
</head>
<body class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

<?php if ($isEmbedded): ?>
<div id="embed-loader" aria-hidden="true"></div>
<script>
  // Embed loader: hide once the page is ready. Map and viewer scripts can also
  // call window.dismissEmbedLoader() earlier — whichever happens first wins.
  (function () {
    var hidden = false;
    window.dismissEmbedLoader = function () {
      if (hidden) return;
      hidden = true;
      var el = document.getElementById('embed-loader');
      if (!el) return;
      el.classList.add('is-hidden');
      setTimeout(function () { el.parentNode && el.parentNode.removeChild(el); }, 400);
    };
    // Dismiss as soon as the document is parsed and our stylesheets have
    // applied — at that point the page's own UI (viewer progress bar, map
    // canvas) is ready to take over. map.js / viewer.js can also dismiss
    // earlier if they reach a meaningful ready state first.
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        setTimeout(window.dismissEmbedLoader, 50);
      });
    } else {
      setTimeout(window.dismissEmbedLoader, 50);
    }
    // Hard fallback so we never trap the visitor behind a spinner.
    setTimeout(window.dismissEmbedLoader, 6000);
  })();
</script>
<?php endif ?>

<?php
// $isVisitor is set by templates that opt in (currently project.php).
// When true, render a stripped, Matterport-style header: small wordmark
// on the left, single "Carte des projets" CTA on the right, nothing else.
// Admins/logged-in users get the full nav; embedded mode renders no
// header at all.
$isVisitor = $isVisitor ?? false;
?>

<?php if (!$isEmbedded && $isVisitor): ?>
<!-- ── Visitor header: minimal chrome, content-first ──────────────── -->
<header class="visitor-header sticky top-0 z-50 bg-white">
  <div class="visitor-header__inner">
    <a class="visitor-header__brand no-underline hover:no-underline"
       href="<?= $site->url() ?>"
       aria-label="<?= $site->title()->html() ?>">
      <img src="<?= url('assets/logos/goheritage.svg') ?>" alt="GoHéritage" class="visitor-header__logo">
    </a>

    <a class="visitor-header__cta no-underline"
       href="<?= url('map') ?>">
      <span>Carte des projets</span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <line x1="5" y1="12" x2="19" y2="12"/>
        <polyline points="12 5 19 12 12 19"/>
      </svg>
    </a>
  </div>
</header>

<?php elseif (!$isEmbedded): ?>
<!-- ── Full site header: navigation for admins / logged-in users ──── -->
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
    <nav class="site-nav col-2 flex w-full items-center justify-between" id="site-nav" aria-label="Navigation principale">
      <?php foreach ($site->children()->listed()->not($site->homePage())->filter(fn($p) => $p->template()->name() !== 'blog') as $item): ?>
      <a
        class="font-sans text-sm uppercase tracking-wider text-ink no-underline transition-colors duration-150 hover:underline hover:text-ink"
        href="<?= $item->url() ?>"
        <?php e($item->isOpen(), 'aria-current="page"') ?>
      ><?= $item->title()->html() ?></a>
      <?php endforeach ?>

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
<?php endif ?>

<main<?= $isMapPage ? ' id="map-main"' : '' ?>>
