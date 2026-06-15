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
        <li><a href="https://www.govr.eu/articles" target="_blank" rel="noopener"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Blog GOVR ↗</a>
        </li>
      </ul>
    </div>



    <!-- social links -->
    <div class="col-1">
      <h2 class="font-mono text-xs uppercase tracking-wider text-[#6B6965] mb-4">Nous suivre</h2>
      <ul class="list-none flex flex-col gap-2">
      <?php
      // Only show entries that have BOTH a platform name and a real http(s)
      // link. This drops half-filled rows (the panel sync already does the
      // same) and, with esc() on the href, blocks a stored javascript:/data:
      // URL from becoming a clickable XSS vector.
      $socials = $site->social()->toStructure()->filter(function ($s) {
          $url = trim((string) $s->url());
          return $url !== ''
              && preg_match('~^https?://~i', $url)
              && trim((string) $s->platform()) !== '';
      });
      ?>
      <?php foreach ($socials as $social): ?>
        <li><a href="<?= esc($social->url(), 'attr') ?>" target="_blank" rel="noopener"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline"><?= $social->platform()->esc() ?></a>
        </li>
      <?php endforeach ?>
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
  <script src="<?= ghAsset('assets/js/lightbox.js') ?>"></script>
<?php endif ?>
<?php
// ghAsset() serves the minified build in production, the source in dev, and
// appends a filemtime cache-bust automatically — so phones re-fetch after a
// deploy with no manual version bumping.
foreach ($jsFiles as $ghJs): ?>
  <script src="<?= ghAsset($ghJs) ?>"></script>
<?php endforeach ?>

</body>

</html>