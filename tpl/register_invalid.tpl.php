<?php
/**
 * register_invalid.tpl.php
 *
 * Page shown when a /register/confirm token is invalid, used or expired.
 * Offers a resend form (POST /register/resend).
 *
 * Expected variables:
 * - $csrfToken: CSRF token for the resend form
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Lien invalide';
$pageClass = 'register-invalid-page';

include __DIR__ . '/layout.tpl.php';

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <h1>Lien invalide ou expire</h1>
        </div>

        <p>
            Le lien de confirmation que vous avez utilise n'est plus valide.
            Cela peut etre du a une expiration (24 heures) ou a une utilisation precedente.
        </p>

        <p>
            Vous pouvez demander un nouveau lien en saisissant l'adresse utilisee a l'inscription :
        </p>

        <form method="POST" action="/register/resend" class="login-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken ?? '') ?>">

            <div class="form-group">
                <label for="email">Adresse e-mail</label>
                <input type="email"
                       id="email"
                       name="email"
                       required
                       autofocus
                       autocomplete="email"
                       autocapitalize="none"
                       spellcheck="false">
            </div>

            <button type="submit" class="btn btn-primary">Renvoyer un lien de confirmation</button>
        </form>

        <div class="login-footer">
            <a href="/login">Retour a la connexion</a>
        </div>
    </div>
</body>
</html>
