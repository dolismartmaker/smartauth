<?php
/**
 * email_alternative_invalid.tpl.php
 *
 * Page shown when a /email-alternative/confirm token is invalid or expired.
 *
 * Expected variables:
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Lien invalide';
$pageClass = 'email-alternative-invalid-page';

include __DIR__ . '/layout.tpl.php';
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Lien invalide ou expiré</h1>
        </div>

        <p>
            Le lien que vous avez utilisé pour confirmer une adresse alternative
            n'est plus valide. Cela peut être dû à une expiration (24 heures)
            ou à une utilisation précédente.
        </p>

        <p>
            Vous pouvez en demander un nouveau depuis la page <a href="/account">Mon compte</a>,
            section "Emails alternatifs par service".
        </p>

        <div class="login-footer">
            <a href="/account">Retour à mon compte</a>
        </div>
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
