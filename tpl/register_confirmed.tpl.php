<?php
/**
 * register_confirmed.tpl.php
 *
 * Page shown after a successful /register/confirm. Auto-redirects to login.
 *
 * Expected variables:
 * - $loginUrl: URL to the login page (with continue if applicable, already validated)
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Compte activé';
$pageClass = 'register-confirmed-page';

$loginUrl = $loginUrl ?? '/login';

include __DIR__ . '/layout.tpl.php';

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Compte activé</h1>
        </div>

        <p>Votre adresse e-mail a été confirmée. Votre compte est désormais actif.</p>

        <p>
            <a href="<?= $h($loginUrl) ?>" class="btn btn-primary">Se connecter</a>
        </p>

        <p class="form-hint">
            Vous serez redirigé automatiquement dans 5 secondes.
        </p>

        <meta http-equiv="refresh" content="5;url=<?= $h($loginUrl) ?>">
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
