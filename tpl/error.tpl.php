<?php
/**
 * error.tpl.php
 *
 * Error page template for SmartAuth OAuth2/OIDC.
 * Displays errors when redirect is not possible.
 *
 * Expected variables:
 * - $error: Error code
 * - $errorDescription: Human-readable error description
 * - $issuer: Issuer URL
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

// Set page variables for layout
$pageTitle = 'Erreur';
$pageClass = 'error-page';

// Include layout header
include __DIR__ . '/layout.tpl.php';
?>
    <div class="error-container">
        <div class="error-icon">
            <span class="icon-warning">!</span>
        </div>

        <h1>Une erreur est survenue</h1>

        <div class="error-details">
            <p class="error-description">
                <?= htmlspecialchars($errorDescription, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p class="error-code">
                Code d'erreur : <code><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></code>
            </p>
        </div>

        <div class="error-actions">
            <a href="/" class="btn btn-secondary">Retour a l'accueil</a>
        </div>

        <div class="error-footer">
            <p>
                Si le probleme persiste, veuillez contacter l'administrateur.
            </p>
        </div>
    </div>

<style>
/* Error page specific styles */
.error-container {
    max-width: 450px;
    margin: 3rem auto;
    padding: 2rem;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    text-align: center;
}

.error-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 1.5rem;
    background: #fee2e2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.icon-warning {
    font-size: 32px;
    font-weight: bold;
    color: #dc2626;
}

.error-container h1 {
    font-size: 1.5rem;
    color: #333;
    margin: 0 0 1.5rem;
}

.error-details {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}

.error-description {
    margin: 0 0 0.5rem;
    color: #555;
}

.error-code {
    margin: 0;
    font-size: 0.85rem;
    color: #777;
}

.error-code code {
    background: #e9ecef;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-family: monospace;
}

.error-actions {
    margin-bottom: 1.5rem;
}

.error-actions .btn {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    text-decoration: none;
    border-radius: 6px;
    transition: background-color 0.2s;
}

.btn-secondary {
    background: #6c757d;
    color: #fff;
}

.btn-secondary:hover {
    background: #545b62;
}

.error-footer {
    padding-top: 1rem;
    border-top: 1px solid #eee;
}

.error-footer p {
    margin: 0;
    font-size: 0.85rem;
    color: #888;
}
</style>
</body>
</html>
