<?php
/**
 * invite-landing snippet
 *
 * Renders the public-facing page a recipient sees after clicking an invite
 * link. Handles all four states in one snippet so the URL stays the same
 * across error and success paths:
 *
 *   • active   — show project preview (when scoped) + signup form
 *   • used     — "déjà utilisé" + link to /panel/login
 *   • expired  — "lien expiré" + ask admin for a new one
 *   • invalid  — generic 404-ish message
 *
 * Variables passed in by the plugin routes:
 *   $invite     array|null  — the stored invite (null when status=invalid)
 *   $status     string      — active | used | expired | invalid
 *   $errors     array       — flash list shown above the form
 *   $form_data  array       — sticky values for email + name on validation fail
 *   $token      string      — token (passed through the form so POST works)
 */

// This snippet is rendered from a custom route (not a template), so $page
// is not in scope and header.php would fail. We emit our own minimal head
// instead — keeps the landing visually clean and dependency-free.
//
// $invite may be null (when status is `invalid`) so every read must be
// null-coalesced — PHP 8 throws TypeError on offset-access of null.

$invite    = $invite ?? null;
$status    = $status ?? 'invalid';
$errors    = $errors ?? [];
$form_data = $form_data ?? [];
$token     = $token ?? '';

$project   = ($invite && !empty($invite['project_id'])) ? page($invite['project_id']) : null;
$cover     = $project ? $project->cover()->toFile() : null;
$coverUrl  = $cover ? $cover->crop(1200, 480)->url() : null;
$siteTitle = site()->title()->or('GoHéritage')->html();

// Messages for the non-active states — used / expired / invalid.
$messages = [
    'used'    => [
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        'title'  => 'Invitation déjà utilisée',
        'body'   => 'Ce lien a déjà servi à créer un compte. Si c\'est le vôtre, connectez-vous via le panneau.',
        'cta'    => ['label' => 'Se connecter', 'href' => url('panel/login')],
    ],
    'expired' => [
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'title'  => 'Invitation expirée',
        'body'   => 'Ce lien a dépassé sa date de validité. Demandez à un administrateur de vous en générer un nouveau.',
        'cta'    => null,
    ],
    'invalid' => [
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        'title'  => 'Lien introuvable',
        'body'   => 'L\'URL est incomplète ou n\'existe plus. Vérifiez que vous avez bien copié l\'intégralité du lien.',
        'cta'    => null,
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Invitation — <?= $siteTitle ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=1">
  <link rel="stylesheet" href="<?= url('assets/css/custom.css') ?>?v=1">
  <link rel="stylesheet" href="<?= url('assets/css/invite-public.css') ?>?v=1">
</head>
<body>

<main class="invite-page">

  <?php if ($status === 'active'): ?>

    <div class="invite-card">

      <?php if ($project): ?>
        <div class="invite-card__preview"<?= $coverUrl ? ' style="background-image:url(' . esc($coverUrl) . ')"' : '' ?>>
          <div class="invite-card__preview-overlay">
            <span class="invite-card__chip">Vous êtes invité·e à rejoindre&nbsp;:</span>
            <h1 class="invite-card__project-title"><?= $project->title()->esc() ?></h1>
            <?php if ($project->location()->isNotEmpty()): ?>
              <p class="invite-card__location"><?= $project->location()->esc() ?></p>
            <?php endif ?>
          </div>
        </div>
      <?php else: ?>
        <div class="invite-card__intro">
          <h1>Vous êtes invité·e à rejoindre GoHéritage</h1>
          <p>Créez votre compte ci-dessous pour accéder au panneau.</p>
        </div>
      <?php endif ?>

      <div class="invite-card__body">

        <?php if (!empty($invite['hint_message'])): ?>
          <blockquote class="invite-card__message">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span><?= esc($invite['hint_message']) ?></span>
          </blockquote>
        <?php endif ?>

        <?php if (!empty($errors)): ?>
          <ul class="invite-form__errors">
            <?php foreach ($errors as $err): ?>
              <li><?= esc($err) ?></li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>

        <form method="POST" action="<?= url('register') ?>" class="invite-form" autocomplete="off">
          <input type="hidden" name="token" value="<?= esc($token) ?>">

          <label class="invite-form__field">
            <span class="invite-form__label">Nom complet</span>
            <input type="text" name="name" required autofocus
                   value="<?= esc($form_data['name'] ?? '') ?>"
                   placeholder="ex. Jeanne Dupont">
          </label>

          <label class="invite-form__field">
            <span class="invite-form__label">Email</span>
            <input type="email" name="email" required
                   value="<?= esc($form_data['email'] ?? '') ?>"
                   placeholder="vous@exemple.com">
            <?php if (!empty($invite['hint_email'])): ?>
              <span class="invite-form__hint">Suggéré&nbsp;: <?= esc($invite['hint_email']) ?></span>
            <?php endif ?>
          </label>

          <div class="invite-form__row">
            <label class="invite-form__field">
              <span class="invite-form__label">Mot de passe</span>
              <input type="password" name="password" required minlength="8"
                     autocomplete="new-password"
                     placeholder="8 caractères minimum">
            </label>

            <label class="invite-form__field">
              <span class="invite-form__label">Confirmation</span>
              <input type="password" name="password_confirm" required minlength="8"
                     autocomplete="new-password"
                     placeholder="Répétez le mot de passe">
            </label>
          </div>

          <div class="invite-form__meta">
            <span class="invite-form__role">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Rôle&nbsp;: <strong><?= esc($invite['role'] ?? 'author') ?></strong>
            </span>
            <?php if (!empty($invite['expires_at'])): ?>
              <span class="invite-form__expires">
                Expire le <?= date('d/m/Y', (int) $invite['expires_at']) ?>
              </span>
            <?php endif ?>
          </div>

          <button type="submit" class="invite-form__submit">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            Créer mon compte
          </button>

          <p class="invite-form__legal">
            En créant votre compte, vous acceptez de respecter les conditions
            d'utilisation et la politique de confidentialité de GoHéritage.
          </p>
        </form>
      </div>
    </div>

  <?php else: ?>

    <?php $m = $messages[$status] ?? $messages['invalid']; ?>
    <div class="invite-status">
      <div class="invite-status__icon"><?= $m['icon'] ?></div>
      <h1 class="invite-status__title"><?= esc($m['title']) ?></h1>
      <p class="invite-status__body"><?= esc($m['body']) ?></p>
      <?php if ($m['cta']): ?>
        <a class="invite-status__cta" href="<?= esc($m['cta']['href']) ?>"><?= esc($m['cta']['label']) ?></a>
      <?php endif ?>
      <a class="invite-status__back" href="<?= url() ?>">← Retour à l'accueil</a>
    </div>

  <?php endif ?>

</main>

</body>
</html>
