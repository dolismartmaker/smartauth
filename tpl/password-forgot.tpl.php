<?php
/**
 * password-forgot.tpl.php
 *
 * Rendered by SmartAuth\Api\Account\PasswordHtmlController for both
 * GET and POST /forgot-password. Always shows the email form; the top
 * banner switches between three states (idle / sent / invalid input).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 *
 * @var string      $email Pre-fill value for the email input (empty after success)
 * @var bool        $sent  True after a successful POST: show the generic confirmation
 * @var string|null $error Error code or null. Currently only 'invalid_email'.
 */

include __DIR__ . '/layout.tpl.php';
?>
    <main class="login-container">
        <header class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Mot de passe oublié</h1>
        </header>

<?php if (!empty($sent)): ?>
        <div class="alert alert-success" role="status">
            Si un compte est associé à cette adresse email, vous allez recevoir un lien de réinitialisation dans quelques minutes. Pensez à vérifier vos courriers indésirables.
        </div>
        <p>
            <a href="/login">Retour à la connexion</a>
        </p>
<?php else: ?>
<?php if ($error === 'invalid_email'): ?>
        <div class="alert alert-error" role="alert">
            Adresse email invalide. Vérifiez le format et réessayez.
        </div>
<?php endif; ?>

        <p>
            Indiquez l'adresse email de votre compte. Si elle est connue, un lien de réinitialisation vous sera envoyé.
        </p>

        <form method="POST" action="/forgot-password" class="login-form" novalidate>
            <div class="form-group">
                <label for="email">Adresse email</label>
                <input type="email"
                       id="email"
                       name="email"
                       value="<?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       required
                       autocomplete="email"
                       autofocus>
            </div>

            <button type="submit" class="btn btn-primary">
                Envoyer le lien
            </button>
        </form>

        <div class="login-footer">
            <a href="/login">Retour à la connexion</a>
        </div>
<?php endif; ?>
    </main>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
