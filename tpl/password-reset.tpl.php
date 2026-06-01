<?php
/**
 * password-reset.tpl.php
 *
 * Rendered by SmartAuth\Api\Account\PasswordHtmlController for both
 * GET (linked from the reset email) and POST /reset-password. Shows
 * the new-password form when not done, or a success banner + link to
 * /login once the reset succeeded.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 *
 * @var string      $email Pre-fill value for the email input (from query string)
 * @var string      $token Reset token (from query string or hidden field)
 * @var bool        $done  True after a successful POST: show the success message
 * @var string|null $error One of: 'token_expired', 'invalid_input',
 *                         'rate_limited', 'password_mismatch', 'reset_failed', or null.
 */

include __DIR__ . '/layout.tpl.php';

$errorMessages = [
    'token_expired'     => "Ce lien de réinitialisation a expiré. Demandez-en un nouveau.",
    'invalid_input'     => "Les informations envoyées sont invalides. Vérifiez votre lien et la robustesse du mot de passe.",
    'rate_limited'      => "Trop de tentatives. Patientez quelques minutes avant de réessayer.",
    'password_mismatch' => "Les deux mots de passe saisis ne correspondent pas.",
    'reset_failed'      => "Impossible de réinitialiser le mot de passe. Demandez un nouveau lien si le problème persiste.",
];
?>
    <main class="login-container">
        <header class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Nouveau mot de passe</h1>
        </header>

<?php if (!empty($done)): ?>
        <div class="alert alert-success" role="status">
            Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.
        </div>
        <p>
            <a href="/login" class="btn btn-primary">Se connecter</a>
        </p>
<?php else: ?>
<?php if (!empty($error) && isset($errorMessages[$error])): ?>
        <div class="alert alert-error" role="alert">
            <?= htmlspecialchars($errorMessages[$error], ENT_QUOTES, 'UTF-8') ?>
        </div>
<?php endif; ?>

        <p>
            Choisissez un nouveau mot de passe pour votre compte.
        </p>

        <form method="POST" action="/reset-password" class="login-form" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars((string) ($token ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="email">Adresse email</label>
                <input type="email"
                       id="email"
                       name="email"
                       value="<?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       required
                       readonly
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">Nouveau mot de passe</label>
                <input type="password"
                       id="password"
                       name="password"
                       required
                       minlength="8"
                       autocomplete="new-password"
                       autofocus>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmer le mot de passe</label>
                <input type="password"
                       id="password_confirm"
                       name="password_confirm"
                       required
                       minlength="8"
                       autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary">
                Valider le nouveau mot de passe
            </button>
        </form>

        <div class="login-footer">
            <a href="/login">Retour à la connexion</a>
        </div>
<?php endif; ?>
    </main>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
