<?php
/*
  Global footer — dark design.
*/
$isMapPage  = $page->template()->name() === 'map';
$isEmbedded = !empty(get('embed'));
$jsFiles    = ['assets/js/index.js'];
if ($isMapPage) {
  $jsFiles[] = 'assets/js/map.js';
}
?>
</main>

<?php if (!$isEmbedded): ?>
<footer class="bg-ink text-white pt-20 pb-10">
  <div class="grid-7 mb-20">

    <!-- brand column -->
    <div class="col-3">
      <img src="<?= url('assets/logos/govr.svg') ?>" alt="GOVR" class="h-16 w-auto mb-4 opacity-50">
      <?php if ($site->footer_tagline()->isNotEmpty()): ?>
        <p class="font-sans text-sm text-[#9A9894] max-w-sm mb-4 leading-relaxed"><?= $site->footer_tagline()->nl2br() ?></p>
      <?php endif ?>
      <?php if ($site->footer_email()->isNotEmpty()): ?>
        <a href="mailto:<?= $site->footer_email()->esc() ?>" class="font-mono text-xs text-white hover:underline block"><?= $site->footer_email()->esc() ?></a>
      <?php endif ?>
    </div>

    <!-- spacer -->
    <div class="col-2"></div>

    <!-- navigation links -->
    <div class="col-1">
      <h2 class="font-mono text-xs uppercase tracking-wider text-[#6B6965] mb-4">Naviguer</h2>
      <ul class="list-none flex flex-col gap-2">
        <?php foreach ($site->children()->listed()->filter(fn($p) => $p->template()->name() !== 'blog') as $item): ?>
          <li><a href="<?= $item->url() ?>"
              class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline"><?= $item->title()->esc() ?></a>
          </li>
        <?php endforeach ?>
        <li><a href="https://www.govr.eu/blog" target="_blank" rel="noopener"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Blog GOVR ↗</a>
        </li>
      </ul>
    </div>



    <!-- social links -->
    <div class="col-1">
      <h2 class="font-mono text-xs uppercase tracking-wider text-[#6B6965] mb-4">Nous suivre</h2>
      <ul class="list-none flex flex-col gap-2">
      <?php if ($site->social()->isNotEmpty()): ?>
        <?php foreach ($site->social()->toStructure() as $social): ?>
          <li><a href="<?= $social->url() ?>" target="_blank" rel="noopener"
              class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline"><?= $social->platform()->esc() ?></a>
          </li>
        <?php endforeach ?>
      <?php else: ?>
        <li><a href="https://mastodon.social" target="_blank" rel="noopener"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Mastodon</a>
        </li>
      <?php endif ?>
      </ul>
    </div>

  </div>

  <!-- legal at absolute bottom -->
  <div class="grid-7 pt-10 opacity-40">
    <div class="col-7 flex items-center justify-start">
      <div class="flex gap-8">
        <a href="<?= url('contact') ?>"
          class="font-mono text-[10px] uppercase tracking-widest no-underline hover:text-white hover:no-underline">Mentions
          légales</a>
        <a href="<?= url('contact') ?>"
          class="font-mono text-[10px] uppercase tracking-widest no-underline hover:text-white hover:no-underline">Confidentialité</a>
      </div>
    </div>
  </div>
</footer>
<?php endif ?>

<?php if ($isMapPage): ?>
  <script src="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.js"></script>
<?php endif ?>

<?php if ($page->template()->name() === 'project'): ?>
  <?= js('assets/js/lightbox.js') ?>
<?php endif ?>
<?php
// Versioned manually so mobile browsers actually re-fetch after a deploy —
// Kirby's js() helper adds no cache-busting, and phones cache JS hard. Bump
// GH_ASSET_VER on any change to index.js / map.js.
$ghAssetVer = '3';
foreach ($jsFiles as $ghJs): ?>
  <script src="<?= url($ghJs) ?>?v=<?= $ghAssetVer ?>"></script>
<?php endforeach ?>

</body>

</html>