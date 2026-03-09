<?php
/*
  Global footer — dark design.
  Loads MapLibre JS conditionally on the map page.
*/
$isMapPage = $page->template()->name() === 'map';
$jsFiles = ['assets/js/index.js'];
if ($isMapPage) {
  $jsFiles[] = 'assets/js/map.js';
}
?>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <div class="grid-7">

      <!-- brand column -->
      <div class="col-2">
        <div class="site-footer__brand">
          <img src="<?= url('assets/icons/logo.svg') ?>" alt="" class="site-footer__logo-icon" width="40" height="40">
          <span class="site-footer__logo-text">GO<br>VR</span>
        </div>
        <p class="site-footer__tagline font-serif">
          <?= $site->footer_tagline()->or('Préservation du patrimoine en danger grâce à la photogrammétrie et au scan 3D.')->html() ?>
        </p>
      </div>

      <!-- spacer -->
      <div class="col-2"></div>

      <!-- navigation links -->
      <div class="col-1">
        <h2 class="footer-col__heading font-mono">Naviguer</h2>
        <ul class="footer-col__list font-serif">
          <?php foreach ($site->children()->listed() as $item): ?>
            <li><a href="<?= $item->url() ?>"><?= $item->title()->esc() ?></a></li>
          <?php endforeach ?>
        </ul>
      </div>

      <!-- about links -->
      <div class="col-1">
        <h2 class="footer-col__heading font-mono">À propos</h2>
        <ul class="footer-col__list font-serif">
          <li><a href="<?= url('map') ?>">Carte du patrimoine</a></li>
          <li><a href="<?= url('notes') ?>">Blog</a></li>
          <li><a href="<?= url('contact') ?>">Contact</a></li>
        </ul>
      </div>

      <!-- social links -->
      <div class="col-1">
        <h2 class="footer-col__heading font-mono">Suivez-nous</h2>
        <ul class="footer-col__list font-serif">
          <li><a href="https://mastodon.social" target="_blank" rel="noopener">Mastodon</a></li>
          <li><a href="https://instagram.com" target="_blank" rel="noopener">Instagram</a></li>
        </ul>
      </div>

    </div>

    <!-- footer search -->
    <div class="site-footer__search">
      <input type="text" class="footer-search-input" placeholder="Rechercher sur GoHéritage..." aria-label="Rechercher">
    </div>

    <!-- bottom bar -->
    <div class="site-footer__bottom">
      <p class="footer-copy">&copy; <?= date('Y') ?> GoHéritage. Tous droits réservés.</p>
      <div class="footer-bottom__links">
        <a href="<?= url('contact') ?>">Mentions légales</a>
        <a href="<?= url('contact') ?>">Politique de confidentialité</a>
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