<?php snippet('header') ?>

<script>window.HERITAGE_SITES = <?= $sitesJson ?>;</script>

<div class="map-layout">

  <?php /* left panel */ ?>
  <aside class="map-panel" id="map-panel">
    <div class="map-panel__header">
      <h1 class="map-panel__title font-gloucester">
        <?= $page->headline()->or('Heritage Sites')->html() ?>
      </h1>
      <p class="map-panel__count font-mono">
        <?= $projects->count() ?> site<?= $projects->count() !== 1 ? 's' : '' ?> documented
      </p>
    </div>

    <nav class="map-list" id="map-list" aria-label="Heritage sites list">
      <?php foreach ($projects as $project): ?>
        <a class="map-list-item" href="<?= $project->url() ?>" data-id="<?= $project->slug() ?>"
          data-lat="<?= $project->lat()->value() ?>" data-lng="<?= $project->lng()->value() ?>">
          <div class="map-list-item__thumb">
            <?php if ($thumb = $project->cover()): ?>
              <img src="<?= $thumb->resize(160, 120)->url() ?>" alt="<?= $thumb->alt()->html() ?>" loading="lazy">
            <?php else: ?>
              <div class="map-list-item__no-thumb">🏛</div>
            <?php endif ?>
          </div>
          <div class="map-list-item__body">
            <?php if ($project->location()->isNotEmpty()): ?>
              <p class="map-list-item__location font-mono"><?= $project->location()->html() ?></p>
            <?php endif ?>
            <p class="map-list-item__title font-gloucester"><?= $project->title()->html() ?></p>
            <?php if ($project->description()->isNotEmpty()): ?>
              <p class="map-list-item__desc font-serif"><?= $project->description()->html() ?></p>
            <?php endif ?>
          </div>
        </a>
      <?php endforeach ?>
    </nav>
  </aside>

  <?php /* right map */ ?>
  <div class="map-container">
    <div id="heritage-map"></div>
  </div>

</div>

<?php snippet('footer') ?>