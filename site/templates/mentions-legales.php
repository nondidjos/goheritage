<?php
// Mentions légales — statutory identification page.
// Belgian law requires these details to be directly and permanently
// accessible, which is why the footer links here on every page.
snippet('header');

// Rows are only rendered when filled, so an unset field leaves a visible gap
// in the panel rather than an empty label on the public page.
$rows = [
    'Dénomination sociale'  => $page->company_name()->esc(),
    'Forme juridique'       => $page->legal_form()->esc(),
    'Numéro d\'entreprise'  => $page->bce()->esc(),
    'Numéro de TVA'         => $page->vat()->esc(),
    'Tribunal compétent'    => $page->court()->esc(),
];
?>

<section class="py-12 md:py-20">

  <div class="col-7 mb-10">
    <p class="font-mono text-xs uppercase tracking-wider text-faint mb-4">Informations légales</p>
    <h1 class="font-thyssen text-[clamp(2rem,4vw,3.5rem)] text-ink leading-tight">
      <?= $page->title()->esc() ?>
    </h1>
  </div>

  <!-- Identité de l'entreprise -->
  <div class="col-3">
    <h2 class="font-sans font-semibold text-base text-ink mb-4">Éditeur du site</h2>
    <dl class="font-sans text-sm text-mid leading-relaxed">
      <?php foreach ($rows as $label => $value): ?>
        <?php if ($value !== ''): ?>
          <dt class="font-mono text-xs uppercase tracking-wider text-faint mt-4"><?= $label ?></dt>
          <dd class="text-ink"><?= $value ?></dd>
        <?php endif ?>
      <?php endforeach ?>

      <?php if ($page->address()->isNotEmpty()): ?>
        <dt class="font-mono text-xs uppercase tracking-wider text-faint mt-4">Siège social</dt>
        <dd class="text-ink"><?= $page->address()->nl2br() ?></dd>
      <?php endif ?>
    </dl>
  </div>

  <div class="col-1 hidden md:block"></div>

  <!-- Contact + éditeur responsable + hébergeur -->
  <div class="col-3">
    <h2 class="font-sans font-semibold text-base text-ink mb-4">Contact</h2>
    <dl class="font-sans text-sm text-mid leading-relaxed">
      <?php if ($page->email()->isNotEmpty()): ?>
        <dt class="font-mono text-xs uppercase tracking-wider text-faint">Email</dt>
        <dd class="text-ink">
          <a href="mailto:<?= $page->email()->esc() ?>" class="hover:underline"><?= $page->email()->esc() ?></a>
        </dd>
      <?php endif ?>
      <?php if ($page->phone()->isNotEmpty()): ?>
        <dt class="font-mono text-xs uppercase tracking-wider text-faint mt-4">Téléphone</dt>
        <dd class="text-ink"><?= $page->phone()->esc() ?></dd>
      <?php endif ?>
    </dl>

    <?php if ($page->publisher_name()->isNotEmpty()): ?>
      <h2 class="font-sans font-semibold text-base text-ink mt-8 mb-2">Éditeur responsable</h2>
      <p class="font-sans text-sm text-ink leading-relaxed">
        <?= $page->publisher_name()->esc() ?><?php if ($page->publisher_address()->isNotEmpty()): ?>,
          <?= $page->publisher_address()->esc() ?><?php endif ?>
      </p>
    <?php endif ?>

    <?php if ($page->host_name()->isNotEmpty()): ?>
      <h2 class="font-sans font-semibold text-base text-ink mt-8 mb-2">Hébergeur</h2>
      <p class="font-sans text-sm text-mid leading-relaxed">
        <?= $page->host_name()->esc() ?><?php if ($page->host_address()->isNotEmpty()): ?><br>
          <?= $page->host_address()->esc() ?><?php endif ?>
      </p>
    <?php endif ?>
  </div>

  <?php if ($page->ip_text()->isNotEmpty() || $page->privacy_text()->isNotEmpty()): ?>
    <div class="col-7 border-t border-border mt-12"></div>
  <?php endif ?>

  <?php if ($page->ip_text()->isNotEmpty()): ?>
    <div class="col-3 pt-8 md:pr-8">
      <h2 class="font-sans font-semibold text-base text-ink mb-2">Propriété intellectuelle</h2>
      <p class="font-sans text-sm text-mid leading-relaxed"><?= $page->ip_text()->nl2br() ?></p>
    </div>
  <?php endif ?>

  <?php if ($page->privacy_text()->isNotEmpty()): ?>
    <div class="col-3 pt-8 md:pl-8 md:border-l md:border-border" id="donnees">
      <h2 class="font-sans font-semibold text-base text-ink mb-2">Données personnelles</h2>
      <p class="font-sans text-sm text-mid leading-relaxed"><?= $page->privacy_text()->nl2br() ?></p>
    </div>
  <?php endif ?>

</section>

<?php snippet('footer') ?>
