<?php
/**
 * logout.tpl.php
 *
 * Logout confirmation page template for SmartAuth OAuth2/OIDC.
 * Displayed after successful logout when no post_logout_redirect_uri is provided.
 *
 * Expected variables:
 * - $issuer: Issuer URL
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

// Set page variables for layout
$pageTitle = 'Déconnexion';
$pageClass = 'logout-page';

// Include layout header
include __DIR__ . '/layout.tpl.php';
?>
    <div class="logout-container">
        <div class="logout-icon">
            <span class="icon-check">&#10003;</span>
        </div>

        <p class="page-eyebrow">Portail SSO</p>
        <h1>Déconnexion réussie</h1>

        <p class="logout-message">
            Vous avez été déconnecté avec succès.
        </p>

        <p class="logout-info">
            Votre session a été fermée et vos tokens d'accès ont été révoqués.
        </p>

        <div class="logout-actions">
            <a href="/login" class="btn btn-primary">Se reconnecter</a>
        </div>

        <div class="logout-footer">
            <p>
                Vous pouvez fermer cette fenêtre en toute sécurité.
            </p>
        </div>
    </div>

<style>
/* Logout page specific styles */
.logout-container {
    max-width: 450px;
    margin: 3rem auto;
    padding: 2rem;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    text-align: center;
}

.logout-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 1.5rem;
    background: #d1fae5;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.icon-check {
    font-size: 32px;
    font-weight: bold;
    color: #059669;
}

.logout-container h1 {
    font-size: 1.5rem;
    color: #333;
    margin: 0 0 1rem;
}

.logout-message {
    font-size: 1.1rem;
    color: #333;
    margin: 0 0 0.5rem;
}

.logout-info {
    font-size: 0.9rem;
    color: #666;
    margin: 0 0 1.5rem;
}

.logout-actions {
    margin-bottom: 1.5rem;
}

.logout-actions .btn {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    text-decoration: none;
    border-radius: 6px;
    transition: background-color 0.2s;
}

.btn-primary {
    background: #3b82f6;
    color: #fff;
}

.btn-primary:hover {
    background: #2563eb;
}

.logout-footer {
    padding-top: 1rem;
    border-top: 1px solid #eee;
}

.logout-footer p {
    margin: 0;
    font-size: 0.85rem;
    color: #888;
}
</style>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
