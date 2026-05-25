<?php
/**
 * totp-verify snippet
 *
 * Public-facing "enter your 2FA code" page. Reached after a successful
 * password login when the account has TOTP enabled ΓÇö the plugin's
 * user.login:after hook stashes a pending challenge in the session and
 * logs the user back out so they can't poke /panel without finishing.
 *
 * Variables passed in by the route:
 *   $error    string|null  ΓÇö flash error to display
 *   $email    string       ΓÇö the email whose challenge is pending
 *   $expires  int          ΓÇö UNIX timestamp when the challenge times out
 */

$error    = $error   ?? null;
$email    = $email   ?? '';
$expires  = $expires ?? 0;
$secsLeft = max(0, $expires - time());
$siteTitle = site()->title()->or('GoH├⌐ritage')->html();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>V├⌐rification 2FA ΓÇö <?= $siteTitle ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=1">
  <link rel="stylesheet" href="<?= url('assets/css/custom.css') ?>?v=1">
  <link rel="stylesheet" href="<?= url('assets/css/invite-public.css') ?>?v=1">
  <style>
    .totp-page {
      max-width: 420px; margin: 5rem auto; padding: 0 1.25rem;
    }
    .totp-card {
      background: #fff; padding: 2rem 2rem 2.25rem;
      border: 1px solid var(--color-border, rgba(26,25,22,0.12));
      border-radius: 12px; box-shadow: 0 16px 56px rgba(0,0,0,0.06);
      text-align: center;
    }
    .totp-card__icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 56px; height: 56px; border-radius: 50%;
      background: var(--color-surface, #F0EFE9);
      color: var(--color-ink, #1a1916);
      margin-bottom: 1rem;
    }
    .totp-card h1 {
      font-family: var(--font-thyssen, var(--font-sans, system-ui));
      font-size: 1.75rem; margin: 0 0 0.25rem;
    }
    .totp-card__sub {
      color: var(--color-mid, #4A4845); font-size: 0.9rem;
      margin: 0 0 1.5rem;
    }
    .totp-card__email {
      font-family: var(--font-mono, monospace); font-size: 0.78rem;
      color: var(--color-faint, #8C8A85);
    }
    .totp-form { display: flex; flex-direction: column; gap: 1rem; }
    .totp-form__code {
      font-family: var(--font-mono, monospace); font-size: 1.5rem;
      text-align: center; letter-spacing: 0.4em;
      padding: 0.75rem; border: 1px solid var(--color-border);
      border-radius: 6px; outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .totp-form__code:focus {
      border-color: var(--color-ink, #1a1916);
      box-shadow: 0 0 0 3px rgba(26,25,22,0.08);
    }
    .totp-form__error {
      background: #fff0ec; border: 1px solid #ffb8a8; color: #a8321a;
      padding: 0.625rem 0.875rem; border-radius: 6px;
      font-size: 0.875rem; margin: 0;
    }
    .totp-form__submit {
      padding: 0.875rem; background: var(--color-ink, #1a1916);
      color: #fff; border: none; border-radius: 6px;
      font-family: var(--font-mono, monospace); font-size: 0.8125rem;
      font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
      cursor: pointer; transition: background 0.15s, transform 0.15s;
    }
    .totp-form__submit:hover { background: #000; transform: translateY(-1px); }
    .totp-form__hint {
      font-size: 0.78rem; color: var(--color-faint, #8C8A85);
      margin: 0; line-height: 1.5;
    }
    .totp-form__hint a {
      color: var(--color-ink, #1a1916); text-decoration: underline;
    }
    .totp-timer {
      display: inline-block; padding: 0.15rem 0.5rem;
      background: var(--color-surface, #F0EFE9); border-radius: 12px;
      font-family: var(--font-mono, monospace); font-size: 0.72rem;
      color: var(--color-mid, #4A4845);
    }
  </style>
</head>
<body>
<main class="totp-page">
  <div class="totp-card">
    <div class="totp-card__icon">
      <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <h1>V├⌐rification en 2 ├⌐tapes</h1>
    <p class="totp-card__sub">
      Entrez le code ├á 6 chiffres affich├⌐ par votre application d'authentification.
      <br><span class="totp-card__email"><?= esc($email) ?></span>
    </p>

    <?php if ($error): ?>
      <p class="totp-form__error"><?= esc($error) ?></p>
    <?php endif ?>

    <form method="POST" class="totp-form" autocomplete="off">
      <input
        type="text"
        name="code"
        class="totp-form__code"
        inputmode="numeric"
        autocomplete="one-time-code"
        autofocus
        placeholder="000000"
        maxlength="11"
        required
      >
      <button type="submit" class="totp-form__submit">V├⌐rifier</button>
      <p class="totp-form__hint">
        Vous pouvez aussi utiliser un <strong>code de secours</strong> (format <code>xxxxx-xxxxx</code>).
        <br>
        <span class="totp-timer">Expire dans <span id="totp-countdown"><?= floor($secsLeft / 60) ?> min <?= $secsLeft % 60 ?> sec</span></span>
      </p>
      <p class="totp-form__hint">
        Pas acc├¿s ├á votre application&nbsp;? <a href="<?= url('totp/cancel') ?>">Annuler et recommencer</a>
      </p>
    </form>
  </div>
</main>
<script>
  (function () {
    var left = <?= (int) $secsLeft ?>;
    var el = document.getElementById('totp-countdown');
    if (!el) return;
    setInterval(function () {
      left = Math.max(0, left - 1);
      var m = Math.floor(left / 60), s = left % 60;
      el.textContent = m + ' min ' + s + ' sec';
      if (left <= 0) location.href = '/panel/login?totp_expired=1';
    }, 1000);
  })();
</script>
</body>
</html>
