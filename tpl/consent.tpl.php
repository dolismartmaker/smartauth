<?php
/**
 * consent.tpl.php
 *
 * Consent page template for SmartAuth OAuth2/OIDC.
 * Displays application permissions request to user.
 *
 * Expected variables (injected via extract() in the controller):
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 *
 * @var string $csrfToken       CSRF token for form
 * @var object $client          SmartAuthOAuthClient object
 * @var string $clientName      Application name
 * @var string $clientLogo      Application logo URL (optional, may be empty)
 * @var array  $scopes          Array of requested scopes
 * @var array  $scopeInfo       Array of scope information for display
 * @var string $userName        User's full name
 * @var string $userLogin       User's login
 * @var bool   $rememberConsent Whether to show the "remember" checkbox
 * @var string $issuer          Issuer URL
 */

// Set page variables for layout
$pageTitle = 'Autorisation';
$pageClass = 'consent-page';

// Include layout header
include __DIR__ . '/layout.tpl.php';
?>
    <div class="consent-container">
        <div class="consent-header">
            <?php if (!empty($clientLogo)): ?>
            <img src="<?= htmlspecialchars($clientLogo, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?>"
                 class="client-logo"
                 onerror="this.style.display='none'">
            <?php endif; ?>
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Demande d'autorisation</h1>
        </div>

        <div class="consent-intro">
            <p>
                <strong><?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?></strong>
                souhaite accéder à votre compte.
            </p>
            <p class="user-info">
                Connecté en tant que <strong><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></strong>
                (<?= htmlspecialchars($userLogin, ENT_QUOTES, 'UTF-8') ?>)
            </p>
        </div>

        <div class="consent-scopes">
            <h2>Cette application demande l'accès à :</h2>
            <ul class="scope-list">
                <?php foreach ($scopeInfo as $info): ?>
                <li class="scope-item">
                    <div class="scope-icon">
                        <?php
                        // Icon based on scope type
                        $iconClass = 'fa-circle-check';
                        switch ($info['scope']) {
                            case 'openid':
                                $iconClass = 'fa-id-card';
                                break;
                            case 'profile':
                                $iconClass = 'fa-user';
                                break;
                            case 'email':
                                $iconClass = 'fa-envelope';
                                break;
                            case 'groups':
                                $iconClass = 'fa-users';
                                break;
                            case 'roles':
                                $iconClass = 'fa-shield';
                                break;
                            case 'offline_access':
                                $iconClass = 'fa-clock';
                                break;
                        }
                        ?>
                        <span class="icon <?= $iconClass ?>"></span>
                    </div>
                    <div class="scope-details">
                        <strong><?= htmlspecialchars($info['description'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="scope-description"><?= htmlspecialchars($info['description_long'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <form method="POST" action="/oauth/authorize" class="consent-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <?php if ($rememberConsent): ?>
            <div class="form-group remember-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember" value="1" checked>
                    <span>Se souvenir de mon choix pour cette application</span>
                </label>
            </div>
            <?php endif; ?>

            <div class="consent-actions">
                <button type="submit" name="decision" value="deny" class="btn btn-secondary">
                    Refuser
                </button>
                <button type="submit" name="decision" value="allow" class="btn btn-primary">
                    Autoriser
                </button>
            </div>
        </form>

        <div class="consent-footer">
            <p class="security-notice">
                En autorisant cette application, vous lui permettez d'accéder aux informations listées ci-dessus.
                Vous pouvez révoquer cet accès à tout moment depuis les paramètres de votre compte.
            </p>
        </div>
    </div>

<style>
/* Consent page specific styles */
.consent-container {
    width: 100%;
    max-width: 500px;
    margin: auto 1rem;
    padding: 2rem;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.consent-header {
    text-align: center;
    margin-bottom: 1.5rem;
}

.client-logo {
    max-width: 80px;
    max-height: 80px;
    margin-bottom: 1rem;
    border-radius: 8px;
}

.consent-header h1 {
    font-size: 1.5rem;
    color: #333;
    margin: 0;
}

.consent-intro {
    text-align: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #eee;
}

.consent-intro p {
    margin: 0.5rem 0;
    color: #555;
}

.user-info {
    font-size: 0.9rem;
    color: #777;
}

.consent-scopes h2 {
    font-size: 1rem;
    color: #333;
    margin-bottom: 1rem;
}

.scope-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.scope-item {
    display: flex;
    align-items: flex-start;
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
    border-radius: 6px;
}

.scope-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e9ecef;
    border-radius: 50%;
    margin-right: 0.75rem;
    color: #495057;
}

.scope-details {
    flex: 1;
}

.scope-details strong {
    display: block;
    font-size: 0.9rem;
    color: #333;
}

.scope-description {
    display: block;
    font-size: 0.8rem;
    color: #666;
    margin-top: 0.25rem;
}

.consent-form {
    margin-top: 1.5rem;
}

.remember-group {
    margin-bottom: 1.5rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-size: 0.9rem;
    color: #555;
}

.checkbox-label input {
    margin-right: 0.5rem;
}

.consent-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.consent-actions .btn {
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    border-radius: 6px;
    cursor: pointer;
    border: none;
    transition: background-color 0.2s;
}

.btn-primary {
    background: #007bff;
    color: #fff;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-secondary {
    background: #6c757d;
    color: #fff;
}

.btn-secondary:hover {
    background: #545b62;
}

.consent-footer {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #eee;
}

.security-notice {
    font-size: 0.8rem;
    color: #777;
    text-align: center;
    margin: 0;
}

/* Icon placeholders (using CSS shapes instead of font icons) */
.icon {
    display: inline-block;
    width: 16px;
    height: 16px;
}

.icon.fa-id-card::before { content: "ID"; font-size: 8px; }
.icon.fa-user::before { content: "U"; font-size: 10px; }
.icon.fa-envelope::before { content: "@"; font-size: 10px; }
.icon.fa-users::before { content: "G"; font-size: 10px; }
.icon.fa-shield::before { content: "R"; font-size: 10px; }
.icon.fa-clock::before { content: "T"; font-size: 10px; }
.icon.fa-circle-check::before { content: "v"; font-size: 10px; }
</style>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
