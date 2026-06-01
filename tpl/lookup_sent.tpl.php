<?php
/**
 * lookup_sent.tpl.php
 *
 * Generic page after POST /lookup-by-email. Identical regardless of
 * whether an account was found, to mitigate enumeration.
 *
 * Expected variables:
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Vérifiez votre boîte mail';
$pageClass = 'lookup-sent-page';

include __DIR__ . '/layout.tpl.php';
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Vérifiez votre boîte mail</h1>
        </div>

        <p>
            Si un compte est associé à cette adresse, vous recevrez un e-mail dans
            les prochaines minutes avec les indications pour vous connecter.
        </p>

        <div class="login-footer">
            <a href="/login">Retour à la connexion</a>
        </div>
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
