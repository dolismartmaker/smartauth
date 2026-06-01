<?php
/**
 * email_alternative_confirmed.tpl.php
 *
 * Page shown after a successful /email-alternative/confirm.
 *
 * Expected variables:
 * - $email: string The email that was registered as alternative
 * - $service: string|null Service label provided by the persistence hook
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Adresse alternative enregistrée';
$pageClass = 'email-alternative-page';

include __DIR__ . '/layout.tpl.php';

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
    <div class="login-container">
        <div class="login-header">
            <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Adresse alternative enregistrée</h1>
        </div>

        <p>
            L'adresse <strong><?= $h($email ?? '') ?></strong> a bien été enregistrée
            <?php if (!empty($service)): ?>
                comme alternative pour <strong><?= $h($service) ?></strong>.
            <?php else: ?>
                comme adresse alternative.
            <?php endif; ?>
        </p>

        <p>
            Vous pouvez gérer vos adresses alternatives depuis la page <a href="/account">Mon compte</a>.
        </p>

        <div class="login-footer">
            <a href="/account">Retour à mon compte</a>
        </div>
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
