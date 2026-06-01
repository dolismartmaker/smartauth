<?php
/**
 * account.tpl.php
 *
 * Self-service account page.
 *
 * Expected variables:
 * - $csrfToken: string
 * - $user: \User Dolibarr user object (fetched)
 * - $sessions: array of grouped sessions (see AccountService::listActiveSessions)
 * - $deletable: bool true if the user can self-delete
 * - $extraSections: array of ['title' => string, 'html' => string, 'priority' => int]
 * - $flash: ['type' => 'success'|'error', 'message' => string] | null
 * - $issuer: string
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

$pageTitle = 'Mon compte';
$pageClass = 'account-page';

include __DIR__ . '/layout.tpl.php';

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
$formatDate = function ($timestamp) {
    if (!$timestamp) {
        return '-';
    }
    return date('Y-m-d H:i', (int) $timestamp);
};
?>
    <div class="account-container">
        <div class="account-header">
            <p class="page-eyebrow">Portail SSO</p>
            <h1>Mon compte</h1>
            <p class="account-login"><?= $h($user->login ?? '') ?></p>
        </div>

        <?php if ($flash !== null): ?>
        <div class="alert alert-<?= $h($flash['type']) ?>" role="alert">
            <?= $h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Identity section -->
        <section class="account-section">
            <h2>Mon identité</h2>
            <form method="POST" action="/account" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="action" value="update_identity">

                <div class="form-group">
                    <label for="firstname">Prénom</label>
                    <input type="text" id="firstname" name="firstname"
                           value="<?= $h($user->firstname ?? '') ?>"
                           autocomplete="given-name">
                </div>

                <div class="form-group">
                    <label for="lastname">Nom</label>
                    <input type="text" id="lastname" name="lastname"
                           value="<?= $h($user->lastname ?? '') ?>"
                           autocomplete="family-name">
                </div>

                <div class="form-group">
                    <label for="email">E-mail (lecture seule)</label>
                    <input type="email" id="email" value="<?= $h($user->email ?? '') ?>" disabled>
                </div>

                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </form>
        </section>

        <!-- Password section -->
        <section class="account-section">
            <h2>Changer mon mot de passe</h2>
            <form method="POST" action="/account" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="current_password">Mot de passe actuel</label>
                    <input type="password" id="current_password" name="current_password"
                           required autocomplete="current-password">
                </div>

                <div class="form-group">
                    <label for="new_password">Nouveau mot de passe</label>
                    <input type="password" id="new_password" name="new_password"
                           required autocomplete="new-password"
                           aria-describedby="new-password-hint">
                    <span id="new-password-hint" class="form-hint">12 caractères minimum, majuscules, minuscules et chiffres.</span>
                </div>

                <div class="form-group">
                    <label for="new_password_confirm">Confirmation</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm"
                           required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary">Modifier le mot de passe</button>
            </form>
        </section>

        <!-- Active sessions section -->
        <section class="account-section">
            <h2>Sessions actives</h2>
            <?php if (empty($sessions)): ?>
                <p>Aucune session active.</p>
            <?php else: ?>
                <?php foreach ($sessions as $client): ?>
                    <div class="session-group">
                        <h3>
                            <?php if (!empty($client['client_logo'])): ?>
                                <img src="<?= $h($client['client_logo']) ?>" alt="" style="height:24px;vertical-align:middle;">
                            <?php endif; ?>
                            <?= $h($client['client_name']) ?>
                            <small>(<?= $h($client['client_id']) ?>)</small>
                        </h3>

                        <table class="session-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Créé le</th>
                                    <th>Expire le</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($client['tokens'] as $token): ?>
                                <tr>
                                    <td><?= $h($token['token_type']) ?></td>
                                    <td><?= $h($formatDate($token['datec'])) ?></td>
                                    <td><?= $h($formatDate($token['expires_at'])) ?></td>
                                    <td>
                                        <form method="POST" action="/account" style="display:inline">
                                            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="revoke_session">
                                            <input type="hidden" name="token_rowid" value="<?= (int) $token['rowid'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Révoquer</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

                <form method="POST" action="/account" style="margin-top:16px">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="action" value="revoke_all">
                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('Déconnecter toutes les sessions ?');">
                        Déconnecter toutes mes sessions
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <?php
        // Sanitiser used for hook-provided HTML. Per M-8 of TODO-SECURITY-01,
        // a misbehaving module must not be able to inject <script>, on*=
        // handlers or javascript: URLs into the /account page.
        $sanitiseSectionHtml = static function ($html): string {
            if (!is_string($html)) {
                return '';
            }
            if (function_exists('dol_string_onlythesehtmltags')) {
                return (string) dol_string_onlythesehtmltags($html);
            }
            $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
            $html = preg_replace('#<(iframe|object|embed|style|link|meta)\b[^>]*>.*?</\1>#is', '', $html);
            $html = preg_replace('#<(iframe|object|embed|style|link|meta)\b[^>]*/?>#i', '', $html);
            $html = preg_replace('#\s(on\w+)\s*=\s*"[^"]*"#i', '', $html);
            $html = preg_replace("#\\s(on\\w+)\\s*=\\s*'[^']*'#i", '', $html);
            $html = preg_replace('#\s(on\w+)\s*=\s*[^\s>]+#i', '', $html);
            $html = preg_replace('#javascript\s*:#i', '', $html);
            return (string) $html;
        };
        ?>
        <?php foreach ($extraSections as $section): ?>
            <?php if (!empty($section['html'])): ?>
            <section class="account-section account-section-extra">
                <?php if (!empty($section['title'])): ?>
                    <h2><?= $h($section['title']) ?></h2>
                <?php endif; ?>
                <?= $sanitiseSectionHtml($section['html']) ?>
            </section>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Deletion section -->
        <section class="account-section account-section-danger">
            <h2>Supprimer mon compte</h2>
            <?php if ($deletable): ?>
                <p>
                    Votre compte n'est pas encore lié à un contrat actif. Vous pouvez le
                    supprimer ici. Cette action est irréversible.
                </p>
                <form method="POST" action="/account">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_account">

                    <div class="form-group">
                        <label for="confirm">Tapez <strong>DELETE</strong> pour confirmer</label>
                        <input type="text" id="confirm" name="confirm" required autocomplete="off">
                    </div>

                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('Supprimer définitivement votre compte ?');">
                        Supprimer mon compte
                    </button>
                </form>
            <?php else: ?>
                <p>
                    Votre compte est lié à un client actif. Pour le supprimer, contactez
                    le support.
                </p>
            <?php endif; ?>
        </section>

        <div class="account-footer">
            <a href="/logout">Se déconnecter</a>
        </div>
    </div>
<?php include __DIR__ . '/layout-footer.tpl.php'; ?>
