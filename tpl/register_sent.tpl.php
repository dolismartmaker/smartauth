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

$pageTitle = 'Verifiez votre boite mail';
$pageClass = 'register-sent-page';

include __DIR__ . '/layout.tpl.php';
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <h1>Verifiez votre boite mail</h1>
        </div>

        <p>
            Si l'adresse fournie est valide et n'est pas deja associee a un compte,
            vous recevrez un e-mail de confirmation dans quelques instants.
        </p>

        <p>
            Cliquez sur le lien dans l'e-mail pour activer votre compte.
            Le lien expire dans 24 heures.
        </p>

        <div class="login-footer">
            <a href="/login">Retour a la connexion</a>
        </div>
    </div>
</body>
</html>
