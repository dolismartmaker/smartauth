<?php
/**
 * register_sent.tpl.php
 *
 * Generic confirmation page shown after a /register POST submission.
 * Intentionally identical regardless of whether the email was new or
 * already known, to mitigate enumeration.
 *
 * Expected variables:
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Vérifiez votre boîte mail';
$pageClass = 'register-sent-page';

include __DIR__ . '/layout.tpl.php';
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Vérifiez votre boîte mail</h1>
        </div>

        <p>
            Si l'adresse fournie est valide et n'est pas déjà associée à un compte,
            vous recevrez un e-mail de confirmation dans quelques instants.
        </p>

        <p>
            Cliquez sur le lien dans l'e-mail pour activer votre compte.
            Le lien expire dans 24 heures.
        </p>

        <div class="login-footer">
            <a href="/login">Retour à la connexion</a>
        </div>
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
