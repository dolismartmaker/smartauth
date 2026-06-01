<?php
/**
 * landing.tpl.php
 *
 * Landing page for the SmartAuth SSO portal. Rendered by
 * SmartAuth\Api\LandingController on GET /. The controller injects the
 * variables below via extract() before include-ing this file.
 *
 * The login card is always shown - the portal is pointless without it.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 *
 * @var string $siteName     mysoc->name or 'SmartAuth' (already plain text)
 * @var string $logoMarkup   pre-built <img> tag (HTML, already escaped)
 * @var bool   $showRegister gate the "create account" card
 * @var bool   $showAccount  gate the "manage account" card
 */

include __DIR__ . '/layout.tpl.php';
?>
    <main class="landing-container">
        <header class="landing-header">
            <?= $logoMarkup /* already-built <img> markup, do not re-escape */ ?>
            <p class="landing-eyebrow">Portail SSO</p>
            <h1><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="landing-tagline">Identifiez-vous pour accéder à vos applications.</p>
        </header>

        <nav class="landing-cards" aria-label="Actions principales">
            <a href="/login" class="landing-card">
                <span class="landing-card-title">Se connecter</span>
                <span class="landing-card-desc">Accéder à votre espace personnel.</span>
            </a>

<?php if (!empty($showRegister)): ?>
            <a href="/register" class="landing-card">
                <span class="landing-card-title">Créer un compte</span>
                <span class="landing-card-desc">Première visite ? Inscrivez-vous en quelques étapes.</span>
            </a>
<?php endif; ?>

<?php if (!empty($showAccount)): ?>
            <a href="/account" class="landing-card">
                <span class="landing-card-title">Mon compte</span>
                <span class="landing-card-desc">Gérer vos préférences, vos appareils et vos sessions.</span>
            </a>
<?php endif; ?>
        </nav>

    </main>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
