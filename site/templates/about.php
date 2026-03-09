<?php
/*
  About page template.
  Uses the Kirby layout field for flexible content.
*/
?>
<?php snippet('header') ?>
<?php snippet('intro') ?>
<?php snippet('layouts', ['field' => $page->layout()]) ?>

<aside class="container">
  <div class="grid-7">
    <div class="col-7">
      <h2 class="contact-title font-gloucester">Nous contacter</h2>
    </div>
    <div class="col-2">
      <h3 class="contact-info__label font-mono">Adresse</h3>
      <div class="text font-serif"><?= $page->address() ?></div>
    </div>
    <div class="col-2">
      <h3 class="contact-info__label font-mono">E-mail</h3>
      <p class="font-serif"><?= Html::email($page->email()) ?></p>
      <h3 class="contact-info__label font-mono" style="margin-top:1.5rem;">Téléphone</h3>
      <p class="font-serif"><?= Html::tel($page->phone()) ?></p>
    </div>
    <div class="col-2">
      <h3 class="contact-info__label font-mono">Sur le web</h3>
      <ul class="footer-col__list font-serif">
        <?php foreach ($page->social()->toStructure() as $social): ?>
          <li><?= Html::a($social->url(), $social->platform()) ?></li>
        <?php endforeach ?>
      </ul>
    </div>
  </div>
</aside>

<?php snippet('footer') ?>