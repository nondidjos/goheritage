<?php
// contact page — form with honeypot
snippet('header');
?>

<div class="py-16 md:py-24 min-h-[80vh]">

  <?php /* Title */ ?>
  <div class="col-3" style="grid-column: 3 / span 3">
    <h1 class="font-thyssen text-[clamp(2rem,4vw,3.5rem)] leading-none mb-4">
      <?= $page->headline()->or($page->title())->html() ?>
    </h1>

    <?php if ($page->intro()->isNotEmpty()): ?>
      <p class="font-serif text-base text-mid leading-relaxed mt-4 mb-8"><?= $page->intro()->kt() ?></p>
    <?php endif ?>
  </div>

  <?php /* Form wrapper — display:contents so it doesn't affect grid */ ?>
  <form method="post" action="<?= $page->url() ?>" style="display: contents;">

    <?php /* honeypot */ ?>
    <div class="absolute -left-[9999px]">
      <label for="website">Site web</label>
      <input type="url" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>

    <?php if ($success): ?>
      <div class="col-3" style="grid-column: 3 / span 3; margin-top: 2rem;">
        <div class="font-serif py-5 px-6 border border-[var(--color-ink)] bg-[var(--color-surface)] text-mid">
          Merci — votre message a été envoyé. Nous vous répondrons bientôt.
        </div>
      </div>
    <?php else: ?>

      <?php if (isset($alert['error'])): ?>
        <div class="col-3" style="grid-column: 3 / span 3; margin-top: 2rem; margin-bottom: 1.5rem;">
          <div class="font-serif py-4 px-6 border border-accent bg-[color-mix(in_srgb,var(--color-accent)_6%,var(--color-bg))] text-accent">
            <?= $alert['error'] ?>
          </div>
        </div>
      <?php endif ?>

      <?php /* Name field */ ?>
      <div class="col-1" style="grid-column: 3 / span 1; margin-top: 1.5rem;">
        <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="name">Nom *</label>
        <input
          class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]" style="border-radius:var(--radius-sm)"
          type="text" id="name" name="name" value="<?= esc($data['name'] ?? '', 'attr') ?>" required>
        <?= isset($alert['name']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['name']) . '</span>' : '' ?>
      </div>

      <?php /* Email field */ ?>
      <div class="col-2" style="grid-column: 4 / span 2; margin-top: 1.5rem;">
        <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="email">E-mail *</label>
        <input
          class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]" style="border-radius:var(--radius-sm)"
          type="email" id="email" name="email" value="<?= esc($data['email'] ?? '', 'attr') ?>" required>
        <?= isset($alert['email']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['email']) . '</span>' : '' ?>
      </div>

      <?php /* Message field */ ?>
      <div class="col-3" style="grid-column: 3 / span 3; margin-top: 1.5rem;">
        <label class="block font-mono text-[0.65rem] uppercase tracking-widest text-faint mb-2" for="message">Message *</label>
        <textarea
          class="w-full font-sans text-[0.9375rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-bg)] text-[var(--color-ink)] transition-colors duration-150 resize-y focus:outline-none focus:border-[var(--color-ink)]" style="border-radius:var(--radius-sm)"
          id="message" name="message" rows="7" required><?= esc($data['message'] ?? '') ?></textarea>
        <?= isset($alert['message']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['message']) . '</span>' : '' ?>
      </div>

      <?php /* Submit button */ ?>
      <div class="col-1" style="grid-column: 3 / span 1; margin-top: 2rem;">
        <button type="submit" name="submit" class="btn btn--filled">Envoyer le message</button>
      </div>

    <?php endif ?>

  </form>

  <?php /* Contact info */ ?>
  <?php if ($page->email()->isNotEmpty() || $page->address()->isNotEmpty()): ?>
    <div class="col-3" style="grid-column: 3 / span 3; margin-top: 2.5rem; padding-top: 2rem; border-top: 1px solid var(--color-border); display: flex; gap: 2.5rem;">
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
