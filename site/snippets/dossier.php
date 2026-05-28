<?php
/**
 * dossier.php — read-only shared "dossier" view.
 *
 * Rendered by the goheritage/project-ux `dossier/(:any)` route. This is a
 * self-contained document (its own <head>, no Kirby panel, no session): the
 * full project content + a downloadable file inventory, for recipients of a
 * dossier- or editor-level share link. Access is already validated by the
 * route, so this template only renders.
 *
 * Vars: $page (the project), $shareKey (the token, for the "visite" CTA).
 */
$shareKey = $shareKey ?? get('key');
$keyQuery = $shareKey ? ('?key=' . urlencode($shareKey)) : '';

$cover     = $page->cover()->toFile();
$posterUrl = $cover ? $cover->crop(1600, 700)->url() : null;

// Gallery — explicit field, else fall back to page images (minus textures).
$gallery = $page->gallery()->toFiles();
if ($gallery->count() === 0) {
    $gallery = $page->images()
        ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp'])
        ->filter(fn($f) => !str_contains(strtolower($f->filename()), 'diffuse')
                        && !str_contains(strtolower($f->filename()), 'texture')
                        && !str_contains(strtolower($f->filename()), 'normal_'))
        ->sortBy('sort');
}

$plansList = $page->plans();

$hasModel = $page->file('exterior.obj') || $page->file('interior.obj')
         || $page->file('exterior.glb') || $page->file('interior.glb')
         || $page->viewer_url()->isNotEmpty();

// Spec sheet
$protectionLabels = [
    'classé'   => 'Classé Monument Historique',
    'unesco'   => 'Patrimoine mondial UNESCO',
    'regional' => 'Inventaire Régional',
    'none'     => 'Non protégé',
];
$protectionRaw = $page->protection_status()->value();
$specFields = [
    ['label' => 'Lieu',          'value' => $page->location()->value()],
    ['label' => 'Construction',  'value' => $page->construction_date()->value()],
    ['label' => 'Architecte',    'value' => $page->architect()->value()],
    ['label' => 'Style',         'value' => $page->style()->value()],
    ['label' => 'Dimensions',    'value' => $page->dimensions()->value()],
    ['label' => 'Protection',    'value' => ($protectionRaw && $protectionRaw !== 'none') ? ($protectionLabels[$protectionRaw] ?? $protectionRaw) : ''],
    ['label' => 'Date du scan',  'value' => $page->date()->isNotEmpty() ? $page->date()->toDate('d/m/Y') : ''],
];
$hasSpecs = false;
foreach ($specFields as $sf) { if (!empty($sf['value'])) { $hasSpecs = true; break; } }

// File inventory — every file attached to the page, downloadable.
$files = $page->files()->sortBy('filename', 'asc');

$fileIcon = function (string $ext): string {
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg','jpeg','png','webp','gif','svg','tif','tiff'], true)) return 'image';
    if (in_array($ext, ['obj','glb','gltf','mtl','fbx','stl','ply'], true))         return 'cube';
    if (in_array($ext, ['pdf'], true))                                              return 'pdf';
    if (in_array($ext, ['json','xml','txt','md','csv'], true))                      return 'doc';
    if (in_array($ext, ['zip','rar','7z','gz'], true))                              return 'zip';
    return 'file';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Dossier — <?= $page->title()->html() ?></title>
  <?= css(['assets/css/app.css', 'assets/css/custom.css']) ?>
  <link rel="shortcut icon" type="image/x-icon" href="<?= url('favicon.ico') ?>">
  <style>
    .gh-dossier { max-width: 1080px; margin: 0 auto; padding: 0 1.25rem 5rem; }
    .gh-dossier__bar {
      position: sticky; top: 0; z-index: 50;
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      padding: 0.75rem 1.25rem; margin-bottom: 2rem;
      background: rgba(255,255,255,0.92); backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(0,0,0,0.08);
    }
    .gh-dossier__bar-left { display: flex; align-items: center; gap: 0.75rem; }
    .gh-dossier__bar-logo { height: 1.5rem; width: auto; }
    .gh-dossier__badge {
      font-family: var(--font-mono, monospace); font-size: 0.65rem; text-transform: uppercase;
      letter-spacing: 0.08em; color: #6b6b6b; border: 1px solid rgba(0,0,0,0.12);
      padding: 0.2rem 0.5rem; border-radius: 999px; white-space: nowrap;
    }
    .gh-dossier__visit-btn {
      display: inline-flex; align-items: center; gap: 0.4rem;
      font-family: var(--font-sans, sans-serif); font-size: 0.8rem; font-weight: 600;
      text-decoration: none; color: #fff; background: #1a1a1a;
      padding: 0.5rem 0.9rem; border-radius: 6px; white-space: nowrap;
      transition: background 0.15s;
    }
    .gh-dossier__visit-btn:hover { background: #000; }
    .gh-dossier__hero {
      position: relative; border-radius: 12px; overflow: hidden;
      aspect-ratio: 16 / 7; background: #1a1a1a; margin-bottom: 2rem;
    }
    .gh-dossier__hero img { width: 100%; height: 100%; object-fit: cover; }
    .gh-dossier__hero-overlay {
      position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: flex-end;
      padding: 2rem; background: linear-gradient(to top, rgba(0,0,0,0.7), transparent 60%); color: #fff;
    }
    .gh-dossier__title { font-family: var(--font-display, serif); font-size: clamp(1.8rem, 4vw, 3rem); margin: 0; line-height: 1.05; }
    .gh-dossier__loc { font-family: var(--font-mono, monospace); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.9; margin-top: 0.5rem; }
    .gh-dossier__section { margin: 0 0 3rem; }
    .gh-dossier__h { font-family: var(--font-mono, monospace); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: #1a1a1a; margin: 0 0 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(0,0,0,0.1); }
    .gh-dossier__desc { font-family: var(--font-serif, serif); font-size: 1.05rem; line-height: 1.7; color: #2a2a2a; }
    .gh-dossier__body { font-family: var(--font-serif, serif); font-size: 1rem; line-height: 1.7; color: #2a2a2a; }
    .gh-dossier__body h2 { font-family: var(--font-sans, sans-serif); font-size: 1.25rem; margin: 2rem 0 0.75rem; }
    .gh-dossier__body p { margin: 0 0 1rem; }
    .gh-dossier__body img { max-width: 100%; border-radius: 8px; }
    .gh-dossier__specs { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem 2rem; }
    .gh-dossier__spec-label { font-family: var(--font-mono, monospace); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.06em; color: #8a8a8a; }
    .gh-dossier__spec-val { font-family: var(--font-sans, sans-serif); font-size: 0.95rem; color: #1a1a1a; margin-top: 0.15rem; }
    .gh-dossier__gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
    .gh-dossier__gallery a { display: block; aspect-ratio: 4 / 3; border-radius: 8px; overflow: hidden; background: #eee; }
    .gh-dossier__gallery img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
    .gh-dossier__gallery a:hover img { transform: scale(1.04); }
    .gh-dossier__files { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .gh-dossier__files th { text-align: left; font-family: var(--font-mono, monospace); font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.06em; color: #8a8a8a; padding: 0.5rem 0.6rem; border-bottom: 1px solid rgba(0,0,0,0.1); }
    .gh-dossier__files td { padding: 0.6rem; border-bottom: 1px solid rgba(0,0,0,0.06); color: #2a2a2a; vertical-align: middle; }
    .gh-dossier__files tr:hover td { background: rgba(0,0,0,0.02); }
    .gh-dossier__files a { color: #1a1a1a; text-decoration: none; font-weight: 500; }
    .gh-dossier__files a:hover { text-decoration: underline; }
    .gh-dossier__ext { display: inline-block; font-family: var(--font-mono, monospace); font-size: 0.62rem; font-weight: 600; text-transform: uppercase; color: #6b6b6b; border: 1px solid rgba(0,0,0,0.12); border-radius: 3px; padding: 0.1rem 0.4rem; }
    .gh-dossier__col-size, .gh-dossier__col-date { text-align: right; color: #8a8a8a; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .gh-dossier__dl { display: inline-flex; align-items: center; gap: 0.3rem; color: #4271ae; font-weight: 600; text-decoration: none; font-size: 0.8rem; }
    .gh-dossier__dl:hover { text-decoration: underline; }
    .gh-dossier__model-cta { display: flex; align-items: center; gap: 1rem; padding: 1.25rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; background: #fafafa; }
    .gh-dossier__model-cta-text { flex: 1; }
    .gh-dossier__model-cta-text strong { display: block; font-family: var(--font-sans, sans-serif); font-size: 0.95rem; color: #1a1a1a; }
    .gh-dossier__model-cta-text span { font-size: 0.82rem; color: #6b6b6b; }
    @media (max-width: 50rem) {
      .gh-dossier__col-date, .gh-dossier__files th.gh-dossier__col-date { display: none; }
    }
  </style>
</head>
<body class="gh-dossier-page">

  <div class="gh-dossier__bar">
    <div class="gh-dossier__bar-left">
      <img src="<?= url('assets/logos/goheritage.svg') ?>" alt="GoHéritage" class="gh-dossier__bar-logo">
      <span class="gh-dossier__badge">Dossier partagé · lecture seule</span>
    </div>
    <?php if ($hasModel): ?>
    <a class="gh-dossier__visit-btn" href="<?= $page->url() . $keyQuery ?>">
      Visite 3D
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
    <?php endif ?>
  </div>

  <div class="gh-dossier">

    <!-- Hero -->
    <div class="gh-dossier__hero">
      <?php if ($posterUrl): ?>
        <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>">
      <?php endif ?>
      <div class="gh-dossier__hero-overlay">
        <h1 class="gh-dossier__title"><?= $page->title()->esc() ?></h1>
        <?php if ($page->location()->isNotEmpty()): ?>
          <div class="gh-dossier__loc"><?= $page->location()->esc() ?></div>
        <?php endif ?>
      </div>
    </div>

    <!-- Description -->
    <?php if ($page->description()->isNotEmpty()): ?>
    <div class="gh-dossier__section">
      <p class="gh-dossier__desc"><?= $page->description()->kirbytext() ?></p>
    </div>
    <?php endif ?>

    <!-- 3D model CTA -->
    <?php if ($hasModel): ?>
    <div class="gh-dossier__section">
      <div class="gh-dossier__model-cta">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <div class="gh-dossier__model-cta-text">
          <strong>Modèle 3D disponible</strong>
          <span>Explorez le relevé en visite interactive.</span>
        </div>
        <a class="gh-dossier__visit-btn" href="<?= $page->url() . $keyQuery ?>">Ouvrir la visite</a>
      </div>
    </div>
    <?php endif ?>

    <!-- Fiche technique -->
    <?php if ($hasSpecs): ?>
    <div class="gh-dossier__section">
      <h2 class="gh-dossier__h">Fiche technique</h2>
      <div class="gh-dossier__specs">
        <?php foreach ($specFields as $sf): ?>
          <?php if (!empty($sf['value'])): ?>
          <div>
            <div class="gh-dossier__spec-label"><?= esc($sf['label']) ?></div>
            <div class="gh-dossier__spec-val"><?= esc($sf['value']) ?></div>
          </div>
          <?php endif ?>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>

    <!-- Rich content -->
    <?php if ($page->text()->isNotEmpty()): ?>
    <div class="gh-dossier__section">
      <h2 class="gh-dossier__h">Présentation</h2>
      <div class="gh-dossier__body"><?= $page->text()->toBlocks() ?></div>
    </div>
    <?php endif ?>

    <!-- Gallery -->
    <?php if ($gallery->count() > 0): ?>
    <div class="gh-dossier__section">
      <h2 class="gh-dossier__h">Galerie</h2>
      <div class="gh-dossier__gallery">
        <?php foreach ($gallery as $image): ?>
        <a href="<?= $image->url() ?>" target="_blank" rel="noopener">
          <img src="<?= $image->crop(500, 375)->url() ?>" alt="<?= $image->alt()->or($page->title())->esc() ?>" loading="lazy">
        </a>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>

    <!-- Plans & relevés -->
    <?php if ($plansList && $plansList->count() > 0): ?>
    <div class="gh-dossier__section">
      <h2 class="gh-dossier__h">Plans &amp; relevés</h2>
      <table class="gh-dossier__files">
        <thead>
          <tr><th>Document</th><th class="gh-dossier__col-size">Taille</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($plansList as $plan): ?>
          <tr>
            <td>
              <span class="gh-dossier__ext"><?= strtoupper($plan->extension()) ?></span>
              <?= esc($plan->caption()->or($plan->filename())->value()) ?>
            </td>
            <td class="gh-dossier__col-size"><?= $plan->niceSize() ?></td>
            <td style="text-align:right">
              <a class="gh-dossier__dl" href="<?= $plan->url() ?>" download>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Télécharger
              </a>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <!-- All files -->
    <?php if ($files->count() > 0): ?>
    <div class="gh-dossier__section">
      <h2 class="gh-dossier__h">Tous les fichiers (<?= $files->count() ?>)</h2>
      <table class="gh-dossier__files">
        <thead>
          <tr>
            <th>Nom</th>
            <th class="gh-dossier__col-size">Taille</th>
            <th class="gh-dossier__col-date">Modifié</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $file): ?>
          <tr>
            <td>
              <a href="<?= $file->url() ?>" target="_blank" rel="noopener"><?= esc($file->filename()) ?></a>
            </td>
            <td class="gh-dossier__col-size"><?= $file->niceSize() ?></td>
            <td class="gh-dossier__col-date"><?= $file->modified('d.m.Y') ?></td>
            <td style="text-align:right">
              <a class="gh-dossier__dl" href="<?= $file->url() ?>" download>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Télécharger
              </a>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

  </div>

</body>
</html>
