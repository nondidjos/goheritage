<?php
// contact page — form with honeypot
snippet('header');
?>

<div class="py-12 md:py-20 min-h-[80vh]">
<?php /* removed redundant grid-7 since main > * is already a subgrid */ ?>

    <div class="col-4">
      <h1 class="font-thyssen text-[clamp(2rem,4vw,3.5rem)] leading-none mb-8">
        <?= $page->headline()->or($page->title())->html() ?></h1>

      <?php if ($page->intro()->isNotEmpty()): ?>
        <p class="font-serif text-lg text-mid leading-relaxed mb-10"><?= $page->intro()->kt() ?></p>
      <?php endif ?>

      <?php if ($success): ?>
        <div class="font-serif py-5 px-6 border border-[var(--color-ink)] bg-[var(--color-surface)] text-mid">
          Merci — votre message a été envoyé. Nous vous répondrons bientôt.
        </div>
      <?php else: ?>

        <?php if (isset($alert['error'])): ?>
          <div
            class="font-serif py-4 px-6 mb-6 border border-accent bg-[color-mix(in_srgb,var(--color-accent)_6%,var(--color-bg))] text-accent">
            <?= $alert['error'] ?></div>
        <?php endif ?>

        <form method="post" action="<?= $page->url() ?>">

          <?php /* honeypot */ ?>
          <div class="absolute -left-[9999px]">
            <label for="website">Site web</label>
            <input type="url" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>

          <div class="mb-6">
            <label class="block font-mono text-xs uppercase tracking-wider text-mid mb-2" for="name">Nom *</label>
            <input
              class="w-full font-serif text-[1.0625rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
              type="text" id="name" name="name" value="<?= esc($data['name'] ?? '', 'attr') ?>" required>
            <?= isset($alert['name']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['name']) . '</span>' : '' ?>
          </div>

          <div class="mb-6">
            <label class="block font-mono text-xs uppercase tracking-wider text-mid mb-2" for="email">E-mail *</label>
            <input
              class="w-full font-serif text-[1.0625rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink)] transition-colors duration-150 focus:outline-none focus:border-[var(--color-ink)]"
              type="email" id="email" name="email" value="<?= esc($data['email'] ?? '', 'attr') ?>" required>
            <?= isset($alert['email']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['email']) . '</span>' : '' ?>
          </div>

          <div class="mb-6">
            <label class="block font-mono text-xs uppercase tracking-wider text-mid mb-2" for="message">Message *</label>
            <textarea
              class="w-full font-serif text-[1.0625rem] py-3 px-4 border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink)] transition-colors duration-150 resize-y focus:outline-none focus:border-[var(--color-ink)]"
              id="message" name="message" rows="7" required><?= esc($data['message'] ?? '') ?></textarea>
            <?= isset($alert['message']) ? '<span class="block font-mono text-xs text-accent mt-1">' . esc($alert['message']) . '</span>' : '' ?>
          </div>

          <button type="submit" name="submit" class="btn btn--filled">Envoyer le message</button>

        </form>

      <?php endif ?>
    </div>

    <div class="col-1"></div>

    <div class="col-2">
      <?php if ($page->email()->isNotEmpty()): ?>
        <div class="mb-8">
          <p class="font-mono text-xs uppercase tracking-wider text-faint mb-1">E-mail</p>
          <p class="font-serif text-mid">
            <a href="mailto:<?= $page->email()->html() ?>"><?= $page->email()->html() ?></a>
          </p>
        </div>
      <?php endif ?>

      <?php if ($page->address()->isNotEmpty()): ?>
        <div>
          <p class="font-mono text-xs uppercase tracking-wider text-faint mb-1">Adresse</p>
          <p class="font-serif text-mid whitespace-pre-line"><?= $page->address()->html() ?></p>
        </div>
      <?php endif ?>
    </div>

</div>

<?php snippet('footer') ?>