<?php
// Consumer-facing marketplace home — Patrivia-style.
// Sections: hero search · regions · categories · featured · editorial.
snippet('header');

// Regions = Belgian regions (audience pivot to Demeures Historiques BE).
// Each card filters the map view. The map filter param is read-but-ignored
// today; wiring it is a follow-up.
$projects = page('map') ? page('map')->children()->listed() : pages();

// Counts auto-derived from each project's location string (heritage-helpers
// plugin → site->regionCounts() / page->region()).
$counts = $site->regionCounts();
$regions = [
  ['key' => 'wallonie',  'label' => 'Wallonie',  'count' => $counts['wallonie'],  'img' => null],
  ['key' => 'flandre',   'label' => 'Flandre',   'count' => $counts['flandre'],   'img' => null],
  ['key' => 'bruxelles', 'label' => 'Bruxelles', 'count' => $counts['bruxelles'], 'img' => null],
];

$categories = [
  ['key' => 'chateaux',  'label' => 'Châteaux',          'icon' => 'castle'],
  ['key' => 'abbayes',   'label' => 'Abbayes & églises', 'icon' => 'church'],
  ['key' => 'jardins',   'label' => 'Jardins & parcs',   'icon' => 'tree'],
  ['key' => 'demeures',  'label' => 'Demeures privées',  'icon' => 'home'],
];
?>

<!-- ── Hero — consumer search ────────────────────────────────────────────── -->
<section class="py-10 md:py-16">
  <div class="col-7 relative overflow-hidden rounded-md bg-ink min-h-[60vh] flex items-end">
    <?php if ($heroMedia = $page->heroImage()->toFile()): ?>
      <div class="absolute inset-0 opacity-60">
        <?php if ($heroMedia->type() === 'video'): ?>
          <video src="<?= $heroMedia->url() ?>" class="w-full h-full object-cover" autoplay muted loop playsinline></video>
        <?php else: ?>
          <img src="<?= $heroMedia->url() ?>" alt="" class="w-full h-full object-cover">
        <?php endif ?>
      </div>
    <?php endif ?>

    <div class="relative z-10 px-6 md:px-12 py-10 md:py-16 max-w-3xl">
      <p class="font-mono text-xs uppercase tracking-wider text-white/60 mb-4">
        <?= $page->heroTag()->or('Patrimoine belge') ?>
      </p>
      <h1 class="font-thyssen text-[clamp(2.25rem,5vw,4.5rem)] text-white leading-[1.05] mb-6">
        <?= $page->heroHeading()->or('Découvrez les demeures historiques près de chez vous') ?>
      </h1>
      <p class="font-sans text-white/80 text-base md:text-lg max-w-xl mb-8">
        <?= $page->heroIntro()->or('Visitez, réservez et explorez châteaux, abbayes et demeures privées en Belgique — sur place ou en visite immersive.') ?>
      </p>

      <!-- Search bar (placeholder — submits to map) -->
      <form action="<?= url('map') ?>" method="get" class="flex flex-col sm:flex-row gap-2 max-w-2xl">
        <div class="flex-1 flex items-center bg-white rounded-md overflow-hidden">
          <span class="pl-4 text-faint">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
          </span>
          <input
            type="search"
            name="q"
            placeholder="Rechercher un château, une ville, une région…"
            class="w-full px-3 py-3 font-sans text-sm text-ink bg-white focus:outline-none">
        </div>
        <button type="submit" class="btn btn--orange justify-center px-6 py-3">
          Explorer
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </button>
      </form>
    </div>
  </div>
</section>

<!-- ── Regions ─────────────────────────────────────────────────────────── -->
<section class="py-10 md:py-16">
  <div class="col-7 flex items-end justify-between mb-6">
    <h2 class="font-thyssen text-[clamp(1.75rem,3vw,2.75rem)] text-ink leading-tight">
      <?= $page->regionsHeading()->or('Explorer par région') ?>
    </h2>
    <a href="<?= url('map') ?>" class="font-mono text-xs uppercase tracking-wider text-faint hover:text-ink transition-colors">
      Voir la carte →
    </a>
  </div>

  <div class="col-7 grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">
    <?php foreach ($regions as $r): ?>
      <a href="<?= url('map') . '?region=' . urlencode($r['key']) ?>"
         class="group block relative rounded-md overflow-hidden aspect-[4/3] bg-surface no-underline">
        <?php if ($r['img']): ?>
          <img src="<?= $r['img'] ?>" alt="<?= $r['label'] ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
        <?php endif ?>
        <div class="absolute inset-0 bg-gradient-to-t from-ink/85 via-ink/30 to-transparent"></div>
        <div class="absolute inset-x-0 bottom-0 p-5">
          <p class="font-mono text-[10px] uppercase tracking-wider text-white/70 mb-1">
            <?= $r['count'] ?> propriété<?= $r['count'] > 1 ? 's' : '' ?>
          </p>
          <h3 class="font-thyssen text-2xl md:text-3xl text-white leading-tight">
            <?= $r['label'] ?>
          </h3>
        </div>
      </a>
    <?php endforeach ?>
  </div>
</section>

<!-- ── Categories ──────────────────────────────────────────────────────── -->
<section class="py-10 md:py-16">
  <h2 class="col-7 font-thyssen text-[clamp(1.75rem,3vw,2.75rem)] text-ink leading-tight mb-6">
    <?= $page->categoriesHeading()->or('Par type de patrimoine') ?>
  </h2>

  <div class="col-7 grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4">
    <?php foreach ($categories as $c): ?>
      <a href="<?= url('map') . '?category=' . urlencode($c['key']) ?>"
         class="group flex flex-col items-center justify-center aspect-square rounded-md bg-surface hover:bg-ink hover:text-white transition-colors duration-150 no-underline p-4 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mb-3 text-ink group-hover:text-white">
          <?php if ($c['icon'] === 'castle'): ?>
            <path d="M3 21h18"/><path d="M5 21V8l3-3 4 3 4-3 3 3v13"/><path d="M9 21v-6h6v6"/>
          <?php elseif ($c['icon'] === 'church'): ?>
            <path d="M12 2v6"/><path d="M9 5h6"/><path d="M5 21V11l7-4 7 4v10"/><path d="M9 21v-6h6v6"/>
          <?php elseif ($c['icon'] === 'tree'): ?>
            <path d="M12 22V12"/><path d="M5 14a3 3 0 1 1 1.5-5.6 3 3 0 0 1 5-2.5 3 3 0 0 1 5 2.5A3 3 0 1 1 18 14h-1"/>
          <?php elseif ($c['icon'] === 'home'): ?>
            <path d="M3 12 12 3l9 9"/><path d="M5 10v11h14V10"/>
          <?php endif ?>
        </svg>
        <span class="font-sans text-sm font-medium"><?= $c['label'] ?></span>
      </a>
    <?php endforeach ?>
  </div>
</section>

<!-- ── Featured properties ─────────────────────────────────────────────── -->
<section class="py-10 md:py-16">
  <div class="col-7 flex items-end justify-between mb-6">
    <h2 class="font-thyssen text-[clamp(1.75rem,3vw,2.75rem)] text-ink leading-tight">
      <?= $page->featuredHeading()->or('À l\'affiche cette semaine') ?>
    </h2>
    <a href="<?= url('map') ?>" class="font-mono text-xs uppercase tracking-wider text-faint hover:text-ink transition-colors">
      Tout voir →
    </a>
  </div>

  <div class="col-7 grid grid-cols-1 md:grid-cols-3 gap-6">
    <?php
    $featured = $projects->sortBy('date', 'desc')->limit(3);
    foreach ($featured as $project):
    ?>
      <a href="<?= $project->url() ?>" class="group block no-underline">
        <div class="aspect-[4/3] overflow-hidden rounded-md bg-surface mb-4">
          <?php if ($cover = $project->cover()->toFile()): ?>
            <img src="<?= $cover->crop(800, 600)->url() ?>" alt="<?= $cover->alt()->esc() ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
          <?php endif ?>
        </div>
        <?php if ($project->location()->isNotEmpty()): ?>
          <p class="font-mono text-[10px] uppercase tracking-wider text-faint mb-2">
            <?= $project->location()->esc() ?>
          </p>
        <?php endif ?>
        <h3 class="font-thyssen text-2xl text-ink leading-snug mb-2 group-hover:underline">
          <?= $project->title()->esc() ?>
        </h3>
        <div class="flex items-center justify-between mt-3">
          <?php if ($project->primary_tag()->isNotEmpty()): ?>
            <span class="tag"><?= $project->primary_tag()->esc() ?></span>
          <?php else: ?>
            <span></span>
          <?php endif ?>
          <span class="font-mono text-xs text-mid">
            Visiter →
          </span>
        </div>
      </a>
    <?php endforeach ?>
  </div>
</section>

<!-- ── Editorial / value prop ──────────────────────────────────────────── -->
<section class="py-12 md:py-20">
  <div class="col-7 bg-ink rounded-md overflow-hidden px-6 md:px-16 py-10 md:py-16">
    <p class="font-mono text-xs uppercase tracking-wider text-white/40 mb-6">
      <?= $page->manifestoTag()->or('Notre mission') ?>
    </p>
    <h2 class="font-thyssen text-[clamp(2rem,5vw,4rem)] text-white leading-[0.95] mb-8 max-w-3xl">
      <?= $page->manifestoHeading()->or("Le patrimoine privé belge,\nrendu accessible à tous.") ?>
    </h2>
    <p class="font-sans text-white/70 max-w-2xl mb-10 leading-relaxed">
      <?= $page->manifestoFooterText()->or("Visites immersives en 3D, réservation directe auprès des propriétaires, événements privés. Une seule plateforme pour explorer, réserver et soutenir le patrimoine de demain.") ?>
    </p>
    <div class="flex flex-col sm:flex-row gap-3">
      <a href="<?= url('map') ?>" class="btn btn--orange justify-center">
        Découvrir les propriétés
      </a>
      <a href="<?= url('contact') ?>?as=owner" class="btn btn--secondary justify-center">
        Vous êtes propriétaire ?
      </a>
    </div>
  </div>
</section>

<?php snippet('footer') ?>
