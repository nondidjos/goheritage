<?php
// Dual-side contact — visitor (booking / question) vs owner (list a property).
// Active side comes from ?as= (visitor|owner). Default: visitor.
snippet('header');

$mode = (get('as') === 'owner' || ($data['mode'] ?? null) === 'owner') ? 'owner' : 'visitor';
?>

<div class="py-16 md:py-24 min-h-[80vh]">

  <!-- Title + side toggle -->
  <div class="col-3" style="grid-column: 3 / span 3">
    <h1 class="font-thyssen text-[clamp(2rem,4vw,3.5rem)] leading-none mb-4">
      <?= $page->headline()->or($page->title())->html() ?>
    </h1>

    <?php if ($page->intro()->isNotEmpty()): ?>
      <p class="font-serif text-base text-mid leading-relaxed mt-4 mb-6"><?= $page->intro()->kt() ?></p>
    <?php endif ?>

    <!-- Side toggle: Visiteur / Propriétaire -->
    <div class="inline-flex border border-border rounded-md overflow-hidden mb-8" role="tablist" aria-label="Type de demande">
      <a href="?as=visitor"
         class="px-5 py-2.5 font-mono text-xs uppercase tracking-wider transition-colors duration-150 <?= $mode === 'visitor' ? 'bg-ink text-white' : 'bg-bg text-ink hover:bg-surface' ?>"
         role="tab" aria-selected="<?= $mode === 'visitor' ? 'true' : 'false' ?>">
        Je suis visiteur
      </a>
      <a href="?as=owner"
         class="px-5 py-2.5 font-mono text-xs uppercase tracking-wider transition-colors duration-150 <?= $mode === 'owner' ? 'bg-ink text-white' : 'bg-bg text-ink hover:bg-surface' ?>"
         role="tab" aria-selected="<?= $mode === 'owner' ? 'true' : 'false' ?>">
        Je suis propriétaire
      </a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="col-3" style="grid-column: 3 / span 3;">
      <div class="font-serif py-5 px-6 border border-[var(--color-ink)] bg-[var(--color-surface)] text-mid">
        Merci — votre message a été envoyé. Nous vous répondrons sous 48h.
      </div>
    </div>

  <?php else: ?>

    <?php if (isset($alert['error'])): ?>
      <div class="col-3" style="grid-column: 3 / span 3; margin-bottom: 1.5rem;">
        <div class="font-serif py-4 px-6 border border-accent bg-[color-mix(in_srgb,var(--color-accent)_6%,var(--color-bg))] text-accent">
          <?= $alert['error'] ?>
        </div>
      </div>
    <?php endif ?>

    <form method="post" action="<?= $page->url() ?>" style="display: contents;">

      <!-- honeypot -->
      <div class="absolute -left-[9999px]">
        <label for="website">Site web</label>
        <input type="url" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <input type="hidden" name="mode" value="<?= esc($mode, 'attr') ?>">

      <?php if ($mode === 'visitor'): ?>

        <!-- ── VISITOR FORM ──────────────────────────────────────────── -->
        <div class="col-3" style="grid-column: 3 / span 3; margin-bottom: 1.5rem;">
          <p class="font-mono text-xs uppercase tracking-wider text-faint mb-3">Demande visiteur</p>
          <p class="font-serif text-sm text-mid leading-relaxed">
            Visite, événement privé, question sur une propriété — nous transmettons à l'équipe ou directement au propriétaire.
          </p>
        </div>

        <?php /* Name */ ?>
        <div class="col-1" style="grid-column: 3 / span 1; margin-top: 0.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="name">Nom *</label>
          <input
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            type="text" id="name" name="name" value="<?= esc($data['name'] ?? '', 'attr') ?>" required>
          <?= isset($alert['name']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['name']) . '</span>' : '' ?>
        </div>

        <?php /* Email */ ?>
        <div class="col-2" style="grid-column: 4 / span 2; margin-top: 0.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="email">E-mail *</label>
          <input
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            type="email" id="email" name="email" value="<?= esc($data['email'] ?? '', 'attr') ?>" required>
          <?= isset($alert['email']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['email']) . '</span>' : '' ?>
        </div>

        <?php /* Subject */ ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="subject">Objet</label>
          <select
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            id="subject" name="subject">
            <option value="visite">Information sur une visite</option>
            <option value="reservation">Réserver un événement (mariage, séminaire…)</option>
            <option value="film">Tournage / shooting photo</option>
            <option value="groupe">Visite de groupe / scolaire</option>
            <option value="autre">Autre</option>
          </select>
        </div>

        <?php /* Property of interest */ ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="property">Propriété concernée (si applicable)</label>
          <input
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            type="text" id="property" name="property" placeholder="Ex. Château de Modave"
            value="<?= esc($data['property'] ?? '', 'attr') ?>">
        </div>

        <?php /* Message */ ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="message">Message *</label>
          <textarea
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 resize-y focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            id="message" name="message" rows="6" required><?= esc($data['message'] ?? '') ?></textarea>
          <?= isset($alert['message']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['message']) . '</span>' : '' ?>
        </div>

        <div class="col-1" style="grid-column: 3 / span 1; margin-top: 2rem;">
          <button type="submit" name="submit" class="btn btn--filled w-full justify-center">Envoyer</button>
        </div>

      <?php else: ?>

        <!-- ── OWNER FORM ────────────────────────────────────────────── -->
        <div class="col-3" style="grid-column: 3 / span 3; margin-bottom: 1.5rem;">
          <p class="font-mono text-xs uppercase tracking-wider text-faint mb-3">Demande propriétaire</p>
          <p class="font-serif text-sm text-mid leading-relaxed">
            Vous possédez une demeure historique et souhaitez la référencer sur GoHéritage. Nous gérons la numérisation 3D, la mise en ligne et la commercialisation. Cotisation annuelle + commission sur réservations.
          </p>
        </div>

        <?php /* Owner name */ ?>
        <div class="col-1" style="grid-column: 3 / span 1; margin-top: 0.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="name">Nom *</label>
          <input
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            type="text" id="name" name="name" value="<?= esc($data['name'] ?? '', 'attr') ?>" required>
        </div>

        <?php /* Owner email */ ?>
        <div class="col-2" style="grid-column: 4 / span 2; margin-top: 0.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="email">E-mail *</label>
          <input
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            type="email" id="email" name="email" value="<?= esc($data['email'] ?? '', 'attr') ?>" required>
        </div>

        <?php /* Property name */ ?>
        <div class="col-2" style="grid-column: 3 / span 2; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="property">Nom de la propriété *</label>
          <input
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            type="text" id="property" name="property" placeholder="Ex. Château de Modave"
            value="<?= esc($data['property'] ?? '', 'attr') ?>" required>
        </div>

        <?php /* Region */ ?>
        <div class="col-1" style="grid-column: 5 / span 1; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="region">Région</label>
          <select
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            id="region" name="region">
            <option value="wallonie">Wallonie</option>
            <option value="flandre">Flandre</option>
            <option value="bruxelles">Bruxelles</option>
            <option value="autre">Autre</option>
          </select>
        </div>

        <?php /* Property type */ ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2">Type de patrimoine *</label>
          <div class="flex flex-wrap gap-2">
            <?php foreach ([
              'chateau' => 'Château',
              'abbaye'  => 'Abbaye / église',
              'jardin'  => 'Jardin / parc',
              'demeure' => 'Demeure privée',
              'autre'   => 'Autre',
            ] as $val => $label): ?>
              <label class="cursor-pointer">
                <input type="checkbox" name="property_type[]" value="<?= $val ?>" class="peer sr-only">
                <span class="inline-block px-4 py-2 border border-border rounded-md font-mono text-xs uppercase tracking-wider transition-colors peer-checked:bg-ink peer-checked:text-white peer-checked:border-ink hover:bg-surface">
                  <?= $label ?>
                </span>
              </label>
            <?php endforeach ?>
          </div>
        </div>

        <?php /* Activity types */ ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2">Activités proposées (toutes options possibles)</label>
          <div class="flex flex-wrap gap-2">
            <?php foreach ([
              'visites'  => 'Visites guidées',
              'mariages' => 'Mariages',
              'seminaires' => 'Séminaires / réceptions',
              'tournages' => 'Tournages / shootings',
              'hebergement' => 'Hébergement',
              'evenements' => 'Événements culturels',
            ] as $val => $label): ?>
              <label class="cursor-pointer">
                <input type="checkbox" name="activities[]" value="<?= $val ?>" class="peer sr-only">
                <span class="inline-block px-4 py-2 border border-border rounded-md font-mono text-xs uppercase tracking-wider transition-colors peer-checked:bg-ink peer-checked:text-white peer-checked:border-ink hover:bg-surface">
                  <?= $label ?>
                </span>
              </label>
            <?php endforeach ?>
          </div>
        </div>

        <?php /* Message */ ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
          <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="message">Présentation de votre projet *</label>
          <textarea
            class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 resize-y focus:outline-none focus:border-[var(--color-ink)]"
            style="border-radius:var(--radius-sm)"
            id="message" name="message" rows="6" placeholder="Décrivez votre propriété, vos attentes, et toute information utile."
            required><?= esc($data['message'] ?? '') ?></textarea>
        </div>

        <div class="col-2" style="grid-column: 3 / span 2; margin-top: 2rem;">
          <button type="submit" name="submit" class="btn btn--filled w-full sm:w-auto justify-center">
            Référencer ma propriété
          </button>
        </div>

      <?php endif ?>

    </form>

  <?php endif ?>

  <!-- Contact info -->
  <?php if ($page->email()->isNotEmpty() || $page->address()->isNotEmpty()): ?>
    <div class="col-3" style="grid-column: 3 / span 3; margin-top: 2.5rem; padding-top: 2rem; border-top: 1px solid var(--color-border); display: flex; gap: 2.5rem; flex-wrap: wrap;">
      <?php if ($page->email()->isNotEmpty()): ?>
        <div>
          <p class="font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2">E-mail</p>
          <a class="font-mono text-sm text-ink hover:text-accent transition-colors duration-150"
            href="mailto:<?= $page->email()->html() ?>"><?= $page->email()->html() ?></a>
        </div>
      <?php endif ?>

      <?php if ($page->address()->isNotEmpty()): ?>
        <div>
          <p class="font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2">Adresse</p>
          <p class="font-mono text-sm text-mid leading-relaxed whitespace-pre-line"><?= $page->address()->html() ?></p>
        </div>
      <?php endif ?>
    </div>
  <?php endif ?>

</div>

<?php snippet('footer') ?>
