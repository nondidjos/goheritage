<?php
/**
 * gh-editor-signup snippet
 *
 * Renders the public-facing account creation page for a recipient of an editor share link.
 *
 * Variables passed in by the routes:
 *   $page       Page        — the project page
 *   $token      string      — the share token
 *   $slug       string      — the project slug
 *   $errors     array       — validation errors
 *   $form_data  array       — sticky input values
 *   $status     string      — active | used
 */

$page      = $page ?? null;
$token     = $token ?? '';
$slug      = $slug ?? '';
$errors    = $errors ?? [];
$form_data = $form_data ?? [];
$status    = $status ?? 'active';

$cover     = $page ? $page->cover()->toFile() : null;
$coverUrl  = $cover ? $cover->crop(1200, 480)->url() : null;
$siteTitle = site()->title()->or('GoHéritage')->html();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Créer un compte éditeur — <?= $siteTitle ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=1">
  <link rel="stylesheet" href="<?= url('assets/css/custom.css') ?>?v=1">
  <link rel="stylesheet" href="<?= url('assets/css/invite-public.css') ?>?v=1">
</head>
<body>

<main class="invite-page">

  <?php if ($status === 'active'): ?>

    <div class="invite-card">

      <?php if ($page): ?>
        <div class="invite-card__preview"<?= $coverUrl ? ' style="background-image:url(' . esc($coverUrl) . ')"' : '' ?>>
          <div class="invite-card__preview-overlay">
            <span class="invite-card__chip">Création de compte éditeur&nbsp;:</span>
            <h1 class="invite-card__project-title"><?= $page->title()->esc() ?></h1>
            <?php if ($page->location()->isNotEmpty()): ?>
              <p class="invite-card__location"><?= $page->location()->esc() ?></p>
            <?php endif ?>
          </div>
        </div>
      <?php else: ?>
        <div class="invite-card__intro">
          <h1>Création de compte éditeur — GoHéritage</h1>
          <p>Créez votre compte ci-dessous pour accéder au panneau d'édition du projet.</p>
        </div>
      <?php endif ?>

      <div class="invite-card__body">

        <blockquote class="invite-card__message">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>Ce compte vous donnera des droits de modification restreints au seul projet <strong><?= $page ? $page->title()->html() : '' ?></strong>.</span>
        </blockquote>

        <?php if (!empty($errors)): ?>
          <ul class="invite-form__errors">
            <?php foreach ($errors as $err): ?>
              <li><?= esc($err) ?></li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>

        <form method="POST" action="<?= url('gh-share-register') ?>" class="invite-form" autocomplete="off">
          <input type="hidden" name="token" value="<?= esc($token) ?>">
          <input type="hidden" name="slug" value="<?= esc($slug) ?>">

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
              Rôle&nbsp;: <strong>Éditeur</strong>
            </span>
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

    <div class="invite-status">
      <div class="invite-status__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h1 class="invite-status__title">Invitation déjà utilisée</h1>
      <p class="invite-status__body">Ce lien a déjà servi à créer un compte. Si c'est le vôtre, connectez-vous via le panneau avec votre email et mot de passe.</p>
      <a class="invite-status__cta" href="<?= url('panel/login') ?>">Se connecter</a>
      <a class="invite-status__back" href="<?= url() ?>">← Retour à l'accueil</a>
    </div>

  <?php endif ?>

</main>

</body>
</html>
