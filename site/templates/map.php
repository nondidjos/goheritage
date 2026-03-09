<?php
// map page — fullscreen map layout
// left panel with search, filter, and card-style project list
// right panel with maplibre map
snippet('header');
?>

<script>window.HERITAGE_SITES = <?= $sitesJson ?>;</script>

<!-- breadcrumb -->
<?php snippet('breadcrumb', [
  'items' => [
    ['label' => $page->title()->value()],
  ]
]) ?>

<div class="map-layout">

  <?php /* left panel */ ?>
  <aside class="map-panel" id="map-panel">

    <!-- search & filter bar -->
    <div class="map-panel__search">
      <div class="map-search-bar">
        <svg class="map-search-bar__icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="6.5" cy="6.5" r="5.5" stroke="currentColor" stroke-width="1.5" />
          <line x1="10.5" y1="10.5" x2="15" y2="15" stroke="currentColor" stroke-width="1.5" />
        </svg>
        <input type="search" class="map-search-bar__input font-serif" id="map-search"
          placeholder="Rechercher sur GoHéritage..." aria-label="Rechercher">
      </div>
      <button class="map-filter-btn font-mono" id="map-filter-btn">
        Filtres
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
          <path d="M1 3h12M3 7h8M5 11h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        </svg>
      </button>
    </div>

    <!-- project cards list -->
    <nav class="map-list" id="map-list" aria-label="Liste des sites patrimoniaux">
      <?php foreach ($projects as $project): ?>
        <a class="map-card" href="<?= $project->url() ?>" data-id="<?= $project->slug() ?>"
          data-lat="<?= $project->lat()->value() ?>" data-lng="<?= $project->lng()->value() ?>">
          <div class="map-card__image">
            <?php if ($thumb = $project->cover()): ?>
              <img src="<?= $thumb->crop(600, 400)->url() ?>" alt="<?= $thumb->alt()->html() ?>" loading="lazy">
            <?php else: ?>
              <div class="map-card__no-image">🏛</div>
            <?php endif ?>
          </div>
          <div class="map-card__body">
            <?php if ($project->location()->isNotEmpty()): ?>
              <p class="map-card__location font-mono">
                <svg class="location-pin" width="10" height="12" viewBox="0 0 12 14" fill="none">
                  <path
                    d="M6 0C2.69 0 0 2.69 0 6c0 4.5 6 8 6 8s6-3.5 6-8c0-3.31-2.69-6-6-6zm0 8.5c-1.38 0-2.5-1.12-2.5-2.5S4.62 3.5 6 3.5 8.5 4.62 8.5 6 7.38 8.5 6 8.5z"
                    fill="currentColor" />
                </svg>
                <?= $project->location()->html() ?>
              </p>
            <?php endif ?>
            <p class="map-card__title font-gloucester"><?= $project->title()->html() ?></p>
            <?php if ($project->description()->isNotEmpty()): ?>
              <p class="map-card__desc font-serif"><?= $project->description()->html() ?></p>
            <?php endif ?>
          </div>
        </a>
      <?php endforeach ?>
    </nav>

    <!-- mobile close button -->
    <button class="map-panel__close" id="map-panel-close" aria-label="Fermer le panneau">✕</button>
  </aside>

  <?php /* right map */ ?>
  <div class="map-container">
    <div id="heritage-map"></div>
  </div>

</div>

<?php snippet('footer') ?>