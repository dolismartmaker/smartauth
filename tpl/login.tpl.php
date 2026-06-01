<?php
/**
 * login.tpl.php
 *
 * Login page template for SmartAuth OAuth2/OIDC.
 * Displays username/password form with CSRF protection.
 *
 * Expected variables:
 * - $csrfToken: CSRF token for form
 * - $continue: URL to redirect after login (already escaped)
 * - $error: Error code or null
 * - $errorMessages: Array of error code => message
 * - $issuer: Issuer URL
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

// Set page variables for layout
$pageTitle = 'Connexion';
$pageClass = 'login-page';

// Include layout header
include __DIR__ . '/layout.tpl.php';
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Connexion</h1>
        </div>

        <?php if (!empty($error) && isset($errorMessages[$error])): ?>
        <div class="alert alert-error" role="alert">
            <?= htmlspecialchars($errorMessages[$error], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/login" class="login-form" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="continue" value="<?= $continue ?>">

            <div class="form-group">
                <label for="username">Identifiant</label>
                <input type="text"
                       id="username"
                       name="username"
                       required
                       autofocus
                       autocomplete="username"
                       autocapitalize="none"
                       spellcheck="false"
                       aria-describedby="username-hint">
                <span id="username-hint" class="sr-only">Entrez votre identifiant ou adresse email</span>
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password"
                       id="password"
                       name="password"
                       required
                       autocomplete="current-password"
                       aria-describedby="password-hint">
                <span id="password-hint" class="sr-only">Entrez votre mot de passe</span>
            </div>

            <button type="submit" class="btn btn-primary">
                Se connecter
            </button>
        </form>

        <div class="login-footer">
            <a href="/forgot-password">Mot de passe oublié ?</a>
        </div>
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
