<?php
// contact page — form with honeypot
snippet('header');
?>

<div class="container contact-page">
  <div class="grid-7">

    <div class="col-4">
      <h1 class="contact-title font-gloucester"><?= $page->headline()->or($page->title())->html() ?></h1>

      <?php if ($page->intro()->isNotEmpty()): ?>
        <p class="contact-intro font-serif"><?= $page->intro()->kt() ?></p>
      <?php endif ?>

      <?php if ($success): ?>
        <div class="form-success">
          Merci — votre message a été envoyé. Nous vous répondrons bientôt.
        </div>
      <?php else: ?>

        <?php if (isset($alert['error'])): ?>
          <div class="form-error"><?= $alert['error'] ?></div>
        <?php endif ?>

        <form method="post" action="<?= $page->url() ?>">

          <?php /* honeypot */ ?>
          <div class="honeypot">
            <label for="website">Site web</label>
            <input type="url" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>

          <div class="form-group">
            <label class="form-label font-mono" for="name">Nom *</label>
            <input class="form-input" type="text" id="name" name="name" value="<?= esc($data['name'] ?? '', 'attr') ?>"
              required>
            <?= isset($alert['name']) ? '<span class="form-field-error">' . esc($alert['name']) . '</span>' : '' ?>
          </div>

          <div class="form-group">
            <label class="form-label font-mono" for="email">E-mail *</label>
            <input class="form-input" type="email" id="email" name="email"
              value="<?= esc($data['email'] ?? '', 'attr') ?>" required>
            <?= isset($alert['email']) ? '<span class="form-field-error">' . esc($alert['email']) . '</span>' : '' ?>
          </div>

          <div class="form-group">
            <label class="form-label font-mono" for="message">Message *</label>
            <textarea class="form-textarea" id="message" name="message" rows="7"
              required><?= esc($data['message'] ?? '') ?></textarea>
            <?= isset($alert['message']) ? '<span class="form-field-error">' . esc($alert['message']) . '</span>' : '' ?>
          </div>

          <button type="submit" name="submit" class="btn btn--filled">Envoyer le message</button>

        </form>

      <?php endif ?>
    </div>

    <div class="col-1"></div>

    <div class="col-2">
      <?php if ($page->email()->isNotEmpty()): ?>
        <div style="margin-bottom:var(--baseline-2x);">
          <p class="contact-info__label font-mono">E-mail</p>
          <p class="contact-info__value font-serif">
            <a href="mailto:<?= $page->email()->html() ?>"><?= $page->email()->html() ?></a>
          </p>
        </div>
      <?php endif ?>

      <?php if ($page->address()->isNotEmpty()): ?>
        <div>
          <p class="contact-info__label font-mono">Adresse</p>
          <p class="contact-info__value font-serif" style="white-space:pre-line;"><?= $page->address()->html() ?></p>
        </div>
      <?php endif ?>
    </div>

  </div>
</div>

<?php snippet('footer') ?>