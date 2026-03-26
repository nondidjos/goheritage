<?php
/*
  Global footer — dark design.
*/
$isMapPage = $page->template()->name() === 'map';
$jsFiles = ['assets/js/index.js'];
if ($isMapPage) {
  $jsFiles[] = 'assets/js/map.js';
}
?>
</main>

<footer class="bg-[#1A1916] text-white pt-20 pb-10">
  <div class="grid-7 mb-20">

    <!-- brand column -->
    <div class="col-3">
      <img src="<?= url('assets/logos/govr.svg') ?>" alt="GOVR" class="h-16 w-auto mb-4 opacity-50">
    </div>

    <!-- spacer -->
    <div class="col-1"></div>

    <!-- navigation links -->
    <div class="col-1">
      <h2 class="font-mono text-xs uppercase tracking-wider text-[#6B6965] mb-4">Naviguer</h2>
      <ul class="list-none flex flex-col gap-2">
        <?php foreach ($site->children()->listed() as $item): ?>
          <li><a href="<?= $item->url() ?>"
              class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline"><?= $item->title()->esc() ?></a>
          </li>
        <?php endforeach ?>
      </ul>
    </div>

    <!-- explore links -->
    <div class="col-1">
      <h2 class="font-mono text-xs uppercase tracking-wider text-[#6B6965] mb-4">Explorer</h2>
      <ul class="list-none flex flex-col gap-2">
        <li><a href="<?= url('map') ?>"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Carte</a>
        </li>
        <li><a href="<?= url('blog') ?>"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Blog</a>
        </li>
        <li><a href="<?= url('contact') ?>"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Contact</a>
        </li>
      </ul>
    </div>

    <!-- social links -->
    <div class="col-1">
      <h2 class="font-mono text-xs uppercase tracking-wider text-[#6B6965] mb-4">Suivre</h2>
      <ul class="list-none flex flex-col gap-2">
        <li><a href="https://mastodon.social" target="_blank" rel="noopener"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Mastodon</a>
        </li>
        <li><a href="https://instagram.com" target="_blank" rel="noopener"
            class="font-sans text-sm text-[#9A9894] no-underline transition-colors duration-150 hover:text-white hover:no-underline">Instagram</a>
        </li>
      </ul>
    </div>

  </div>

  <!-- legal & copyright at absolute bottom -->
  <div class="grid-7 pt-10 opacity-40">
    <div class="col-7 flex items-center justify-between">
      <p class="font-mono text-[10px] uppercase tracking-widest">&copy; <?= date('Y') ?> GoHéritage</p>
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

<?php if ($isMapPage): ?>
  <script src="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.js"></script>
<?php endif ?>

<?= js($jsFiles) ?>

</body>

</html>