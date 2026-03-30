<?php
// map page — fullscreen map layout
// left panel with search, filter, and card-style project list
// right panel with maplibre map
snippet('header');
?>

<div class="map-layout w-full" id="map-layout">

  <?php /* left panel */ ?>
  <aside class="col-2 map-panel" id="map-panel">

    <!-- search & filter bar -->
    <div class="map-panel__search">
      <div class="map-search-bar">
        <svg class="map-search-bar__icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="6.5" cy="6.5" r="5.5" stroke="currentColor" stroke-width="1.5" />
          <line x1="10.5" y1="10.5" x2="15" y2="15" stroke="currentColor" stroke-width="1.5" />
        </svg>
        <input type="search" class="map-search-bar__input" id="map-search"
          placeholder="Rechercher..." aria-label="Rechercher">
      </div>
      <button class="map-filter-btn font-mono" id="map-filter-btn">
        Filtres
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
          <path d="M1 3h12M3 7h8M5 11h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        </svg>
      </button>
    </div>

    <!-- tag filter panel — populated and animated by JS -->
    <div class="map-filter-panel" id="map-filter-panel"></div>

    <!-- project cards list -->
    <nav class="map-list" id="map-list" aria-label="Liste des sites patrimoniaux">
      <?php foreach ($projects as $project): ?>
        <div class="map-card" data-id="<?= $project->slug() ?>"
          data-lat="<?= $project->lat()->value() ?>" data-lng="<?= $project->lng()->value() ?>"
          data-tags="<?= htmlspecialchars(json_encode(array_values(array_filter(array_map('trim', $project->tags()->split(','))))), ENT_QUOTES, 'UTF-8') ?>">
          <div class="map-card__image">
            <?php if ($thumb = $project->cover()->toFile()): ?>
              <img src="<?= $thumb->crop(800, 350)->url() ?>" alt="<?= $thumb->alt()->html() ?>" loading="lazy">
            <?php else: ?>
              <div class="map-card__no-image">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-landmark">
                  <line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>
                </svg>
              </div>
            <?php endif ?>
          </div>
          <div class="map-card__body">
            <?php if ($project->location()->isNotEmpty()): ?>
              <p class="map-card__location font-mono">
                <svg class="location-pin" width="10" height="12" viewBox="0 0 12 14" fill="none">
                  <path d="M6 0C2.69 0 0 2.69 0 6c0 4.5 6 8 6 8s6-3.5 6-8c0-3.31-2.69-6-6-6zm0 8.5c-1.38 0-2.5-1.12-2.5-2.5S4.62 3.5 6 3.5 8.5 4.62 8.5 6 7.38 8.5 6 8.5z" fill="currentColor" />
                </svg>
                <?= $project->location()->html() ?>
              </p>
            <?php endif ?>
            <p class="map-card__title"><?= $project->title()->html() ?></p>
            <?php if ($project->description()->isNotEmpty()): ?>
              <p class="map-card__desc font-serif"><?= $project->description()->html() ?></p>
            <?php endif ?>
          </div>
          <div class="map-card__actions flex gap-3 mt-4">
            <button class="btn px-4! py-3! bg-transparent border-border hover:bg-border justify-center" data-action="center" title="Centrer" aria-label="Centrer">
              <svg width="14" height="14" viewBox="0 0 11 11" fill="none">
                <circle cx="5.5" cy="5.5" r="4.5" stroke="currentColor" stroke-width="1.1"/>
                <circle cx="5.5" cy="5.5" r="1.25" fill="currentColor"/>
                <line x1="5.5" y1="0" x2="5.5" y2="2" stroke="currentColor" stroke-width="1.1"/>
                <line x1="5.5" y1="9" x2="5.5" y2="11" stroke="currentColor" stroke-width="1.1"/>
                <line x1="0" y1="5.5" x2="2" y2="5.5" stroke="currentColor" stroke-width="1.1"/>
                <line x1="9" y1="5.5" x2="11" y2="5.5" stroke="currentColor" stroke-width="1.1"/>
              </svg>
            </button>
            <a class="btn flex-1 justify-center bg-ink border-ink text-white hover:bg-ink hover:text-white" href="<?= $project->url() ?>">
              Voir le modèle →
            </a>
          </div>
        </div>
      <?php endforeach ?>
    </nav>

    <!-- mobile close button -->
    <button class="map-panel__close" id="map-panel-close" aria-label="Fermer le panneau">✕</button>
  </aside>

  <?php /* right map */ ?>
  <div class="col-5 map-container" id="map-container">
    <div id="heritage-map" class="absolute inset-0"
         data-sites="<?= htmlspecialchars($sitesJson, ENT_QUOTES, 'UTF-8') ?>"
         data-key="<?= base64_encode(option('maptiler.key')) ?>"></div>
  </div>

</div>

<?php snippet('footer') ?>
