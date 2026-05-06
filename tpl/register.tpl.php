<?php
/**
 * register.tpl.php
 *
 * Public registration form for SmartAuth.
 *
 * Expected variables:
 * - $csrfToken: string CSRF token for POST /register
 * - $clientName: string|null Branding name from client OAuth2 (null = generic)
 * - $clientLogo: string|null Branding logo URL
 * - $clientId: string Public client_id (or '')
 * - $continueUrl: string Continue URL after confirmation (already validated)
 * - $errors: array<string,string> Field-level error messages keyed by field
 * - $values: array<string,string> Previously submitted values (sticky form)
 * - $issuer: string Issuer URL
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Creer un compte';
$pageClass = 'register-page';

$clientName = $clientName ?? '';
$clientLogo = $clientLogo ?? '';
$clientId = $clientId ?? '';
$continueUrl = $continueUrl ?? '';
$errors = $errors ?? [];
$values = $values ?? [];

include __DIR__ . '/layout.tpl.php';

$h = function ($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
    <div class="login-container">
        <div class="login-header">
            <?php if (!empty($clientLogo)): ?>
                <img src="<?= $h($clientLogo) ?>" alt="<?= $h($clientName) ?>" class="logo" onerror="this.style.display='none'">
            <?php else: ?>
                <img src="/assets/img/logo.svg" alt="SmartAuth" class="logo" onerror="this.style.display='none'">
            <?php endif; ?>
            <h1>Creer un compte<?php if (!empty($clientName)): ?> <small> - <?= $h($clientName) ?></small><?php endif; ?></h1>
        </div>

        <?php if (!empty($errors['_global'])): ?>
        <div class="alert alert-error" role="alert">
            <?= $h($errors['_global']) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/register" class="login-form" autocomplete="on" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="client_id" value="<?= $h($clientId) ?>">
            <input type="hidden" name="continue" value="<?= $h($continueUrl) ?>">

            <div class="form-group">
                <label for="email">Adresse e-mail</label>
                <input type="email"
                       id="email"
                       name="email"
                       required
                       autofocus
                       autocomplete="email"
                       autocapitalize="none"
                       spellcheck="false"
                       value="<?= $h($values['email'] ?? '') ?>"
                       aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>">
                <?php if (isset($errors['email'])): ?>
                    <span class="field-error" role="alert"><?= $h($errors['email']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="firstname">Prenom</label>
                <input type="text"
                       id="firstname"
                       name="firstname"
                       autocomplete="given-name"
                       value="<?= $h($values['firstname'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="lastname">Nom</label>
                <input type="text"
                       id="lastname"
                       name="lastname"
                       autocomplete="family-name"
                       value="<?= $h($values['lastname'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password"
                       id="password"
                       name="password"
                       required
                       autocomplete="new-password"
                       aria-describedby="password-hint"
                       aria-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>">
                <span id="password-hint" class="form-hint">12 caracteres minimum, majuscules, minuscules et chiffres.</span>
                <?php if (isset($errors['password'])): ?>
                    <span class="field-error" role="alert"><?= $h($errors['password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmation du mot de passe</label>
                <input type="password"
                       id="password_confirm"
                       name="password_confirm"
                       required
                       autocomplete="new-password"
                       aria-invalid="<?= isset($errors['password_confirm']) ? 'true' : 'false' ?>">
                <?php if (isset($errors['password_confirm'])): ?>
                    <span class="field-error" role="alert"><?= $h($errors['password_confirm']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group form-group-checkbox">
                <label>
                    <input type="checkbox"
                           name="accept_cgu"
                           value="1"
                           required
                           <?= !empty($values['accept_cgu']) ? 'checked' : '' ?>>
                    J'accepte les conditions generales d'utilisation.
                </label>
                <?php if (isset($errors['accept_cgu'])): ?>
                    <span class="field-error" role="alert"><?= $h($errors['accept_cgu']) ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary">Creer mon compte</button>
        </form>

        <div class="login-footer">
            <a href="/login<?= !empty($continueUrl) ? '?continue=' . urlencode($continueUrl) : '' ?>">J'ai deja un compte</a>
        </div>
    </div>
</body>
</html>
