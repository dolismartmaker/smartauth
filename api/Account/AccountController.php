<?php

/**
 * AccountController.php
 *
 * HTTP entry point for the self-service /account page.
 *
 * Routes:
 *   GET  /account                 -> render the page (requires SmartAuth session)
 *   POST /account                 -> handle one of the actions:
 *     - action=update_identity    : firstname / lastname
 *     - action=change_password    : current_password / new_password / new_password_confirm
 *     - action=revoke_session     : token_rowid (one token)
 *     - action=revoke_all         : revoke every token of the user
 *     - action=delete_account     : self-service deletion (prospect + no contract)
 *
 * External modules (e.g. ssomanager) may inject extra sections via the
 * smartmaker_account_sections hook (helper: HookHelper::runAccountSectionsHook).
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

use SmartAuth\Api\OAuth2\HookHelper;
use SmartAuth\Api\OAuth2\OAuthConfig;
use SmartAuth\Api\OAuth2\SessionManager;

dol_include_once('/smartauth/api/OAuth2/SessionManager.php');
dol_include_once('/smartauth/api/OAuth2/HookHelper.php');
dol_include_once('/smartauth/api/Account/EmailValidationToken.php');
dol_include_once('/smartauth/class/smartauthoauthclient.class.php');

class AccountController
{
    private const SESSION_CSRF = 'smartauth_account_csrf';
    private const FLASH_KEY = 'smartauth_account_flash';

    /**
     * @var \DoliDB
     */
    private $db;

    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @var AccountService
     */
    private $accountService;

    /**
     * @var RegistrationService
     */
    private $registrationService;

    public function __construct(
        $db,
        ?AccountService $accountService = null,
        ?RegistrationService $registrationService = null
    ) {
        $this->db = $db;
        $this->sessionManager = new SessionManager($db);
        $this->accountService = $accountService ?? new AccountService($db);
        $this->registrationService = $registrationService ?? new RegistrationService($db);
    }

    /**
     * Dispatch a request to the appropriate handler.
     *
     * @return void
     */
    public function handle(): void
    {
        // Ensure a PHP session is started with secure cookie parameters
        // before reading $_SESSION (CSRF token + flash messages live there).
        // Without this, the controller silently relied on Dolibarr having
        // started the session with module-appropriate parameters - a fragile
        // contract that left CSRF protection without an explicit
        // SameSite=Lax / HttpOnly / Secure on the cookie (H-9).
        $this->ensureSecureSession();

        $userId = $this->requireSession();
        if ($userId === null) {
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST') {
            $this->handlePost($userId);
            return;
        }
        $this->renderPage($userId);
    }

    /**
     * Start a PHP session with hardened cookie parameters if not already
     * active. Safe to call when Dolibarr has already started one - we only
     * touch session_set_cookie_params before session_start.
     */
    private function ensureSecureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }

    /**
     * Validate that the user has an active SmartAuth session, redirecting
     * to /login otherwise. Returns the user id or null after the redirect.
     *
     * @return int|null
     */
    private function requireSession(): ?int
    {
        $userId = $this->sessionManager->validateSession();
        if ($userId === null) {
            $continue = '/account';
            header('Location: /login?continue=' . urlencode($continue));
            exit;
        }
        return $userId;
    }

    /**
     * Handle POST /account.
     *
     * @param int $userId
     */
    private function handlePost(int $userId): void
    {
        $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

        if (!$this->validCsrf($csrf)) {
            $this->setFlash('error', 'Session expiree. Veuillez recharger la page.');
            $this->redirectToSelf();
            return;
        }
        // Rotate the CSRF token after each successful POST
        unset($_SESSION[self::SESSION_CSRF]);

        switch ($action) {
            case 'update_identity':
                $this->actionUpdateIdentity($userId);
                break;
            case 'change_password':
                $this->actionChangePassword($userId);
                break;
            case 'revoke_session':
                $this->actionRevokeSession($userId);
                break;
            case 'revoke_all':
                $this->actionRevokeAll($userId);
                break;
            case 'delete_account':
                $this->actionDeleteAccount($userId);
                break;
            case 'request_email_alternative':
                $this->actionRequestEmailAlternative($userId);
                break;
            default:
                $this->setFlash('error', 'Action inconnue.');
                $this->redirectToSelf();
        }
    }

    private function actionUpdateIdentity(int $userId): void
    {
        $firstname = isset($_POST['firstname']) ? trim((string) $_POST['firstname']) : '';
        $lastname = isset($_POST['lastname']) ? trim((string) $_POST['lastname']) : '';

        $result = $this->accountService->updateIdentity($userId, $firstname, $lastname);
        if ($result < 0) {
            $this->setFlash('error', 'Impossible de mettre a jour votre profil. Reessayez plus tard.');
        } else {
            $this->setFlash('success', 'Profil mis a jour.');
        }
        $this->redirectToSelf();
    }

    private function actionChangePassword(int $userId): void
    {
        $current = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
        $new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $newConfirm = isset($_POST['new_password_confirm']) ? (string) $_POST['new_password_confirm'] : '';

        $result = $this->accountService->changePassword($userId, $current, $new, $newConfirm);
        switch ($result) {
            case AccountService::ERR_CURRENT_PASSWORD_WRONG:
                $this->setFlash('error', 'Mot de passe actuel incorrect.');
                break;
            case AccountService::ERR_PASSWORD_MISMATCH:
                $this->setFlash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');
                break;
            case AccountService::ERR_WEAK_PASSWORD:
                $this->setFlash('error', 'Le nouveau mot de passe est trop faible (12 caracteres minimum, majuscules, minuscules et chiffres).');
                break;
            case AccountService::ERR_USER_NOT_FOUND:
            case AccountService::ERR_INTERNAL:
                $this->setFlash('error', 'Impossible de modifier le mot de passe. Reessayez plus tard.');
                break;
            default:
                if ($result > 0) {
                    $this->setFlash('success', 'Mot de passe mis a jour.');
                } else {
                    $this->setFlash('error', 'Impossible de modifier le mot de passe.');
                }
        }
        $this->redirectToSelf();
    }

    private function actionRevokeSession(int $userId): void
    {
        $tokenRowId = isset($_POST['token_rowid']) ? (int) $_POST['token_rowid'] : 0;
        $result = $this->accountService->revokeSessionByRowId($userId, $tokenRowId);
        if ($result < 0) {
            $this->setFlash('error', 'Session introuvable ou deja revoquee.');
        } else {
            $this->setFlash('success', 'Session revoquee.');
        }
        $this->redirectToSelf();
    }

    private function actionRevokeAll(int $userId): void
    {
        $count = $this->accountService->revokeAllSessions($userId);
        if ($count < 0) {
            $this->setFlash('error', 'Impossible de revoquer les sessions.');
        } else {
            $this->setFlash('success', 'Toutes vos sessions ont ete revoquees (' . $count . ').');
        }
        $this->redirectToSelf();
    }

    /**
     * Generate an email_change token and send a confirmation email so the
     * user can register an alternative email for a given OAuth2 client.
     *
     * The actual persistence (insertion into ssomanager's table) is done
     * later, when the user clicks the confirmation link, via the
     * `smartmaker_email_alternative_persist` hook.
     *
     * Expected POST fields: email, client_pk (or client_id).
     */
    private function actionRequestEmailAlternative(int $userId): void
    {
        $email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
        $clientPk = isset($_POST['client_pk']) ? (int) $_POST['client_pk'] : 0;
        $clientIdInput = isset($_POST['client_id']) ? trim((string) $_POST['client_id']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->setFlash('error', 'Adresse e-mail invalide.');
            $this->redirectToSelf();
            return;
        }

        $client = $this->resolveOAuthClient($clientPk, $clientIdInput);
        if ($client === null) {
            $this->setFlash('error', 'Service inconnu pour cet utilisateur.');
            $this->redirectToSelf();
            return;
        }

        $tokens = new EmailValidationToken($this->db);
        $plain = EmailValidationToken::generatePlainToken();
        $context = [
            'email' => $email,
            'client_pk' => (int) $client->id,
            'client_id' => (string) $client->client_id,
            'source' => 'account_self_service',
        ];

        $rowId = $tokens->create(
            $userId,
            EmailValidationToken::PURPOSE_EMAIL_CHANGE,
            EmailValidationToken::hashToken($plain),
            OAuthConfig::getRegisterTokenTTL(),
            $this->getClientIp(),
            $context
        );
        if ($rowId <= 0) {
            $this->setFlash('error', 'Impossible de generer le lien de confirmation. Reessayez plus tard.');
            $this->redirectToSelf();
            return;
        }

        if (!$this->sendEmailAlternativeConfirmation($email, $plain, $client)) {
            $this->setFlash('error', 'L\'enregistrement a echoue : impossible d\'envoyer l\'e-mail de confirmation.');
            $this->redirectToSelf();
            return;
        }

        $this->setFlash('success', 'Un e-mail de confirmation a ete envoye a ' . $email . '. Cliquez sur le lien dans le mail pour valider l\'adresse alternative.');
        $this->redirectToSelf();
    }

    /**
     * Resolve an OAuth client object from either its row id or public client_id.
     *
     * @param int    $clientPk
     * @param string $clientIdInput
     * @return \SmartAuthOAuthClient|null
     */
    private function resolveOAuthClient(int $clientPk, string $clientIdInput): ?\SmartAuthOAuthClient
    {
        if ($clientPk <= 0 && $clientIdInput === '') {
            return null;
        }
        $client = new \SmartAuthOAuthClient($this->db);
        if ($clientPk > 0) {
            $result = $client->fetch((int) $clientPk);
        } else {
            $result = $client->fetch(0, null, $clientIdInput);
        }
        if ($result <= 0) {
            return null;
        }
        if (method_exists($client, 'isEnabled') && !$client->isEnabled()) {
            return null;
        }
        return $client;
    }

    /**
     * Send the email-alternative confirmation message.
     *
     * @param string                 $to
     * @param string                 $plainToken
     * @param \SmartAuthOAuthClient  $client
     * @return bool
     */
    private function sendEmailAlternativeConfirmation(string $to, string $plainToken, \SmartAuthOAuthClient $client): bool
    {
        $issuer = OAuthConfig::getIssuer();
        $confirmUrl = $issuer . '/email-alternative/confirm?token=' . urlencode($plainToken);

        $htmlTpl = dirname(__DIR__, 2) . '/tpl/email/email_alternative_confirmation.html.php';

        $vars = [
            'issuer' => $issuer,
            'confirmUrl' => $confirmUrl,
            'serviceName' => (string) ($client->name ?? ''),
            'serviceLogoUrl' => (string) ($client->logo_url ?? ''),
            'email' => $to,
        ];

        $htmlBody = $this->renderTemplateToString($htmlTpl, $vars);
        $subject = 'Confirmez l\'adresse alternative pour ' . (string) ($client->name ?? 'votre service');

        return $this->dispatchEmailViaDolibarr($to, $subject, $htmlBody);
    }

    /**
     * Render a PHP template path to a string.
     *
     * @param string $path
     * @param array  $vars
     * @return string
     */
    private function renderTemplateToString(string $path, array $vars): string
    {
        if (!file_exists($path)) {
            dol_syslog('[SmartAuth] AccountController: missing template ' . $path, LOG_ERR);
            return '';
        }
        ob_start();
        extract($vars, EXTR_SKIP);
        include $path;
        return (string) ob_get_clean();
    }

    /**
     * Dispatch an email through Dolibarr's CMailFile.
     *
     * The plain-text alternative body is auto-generated by CMailFile from the
     * HTML when msgishtml=1, so no custom text body is passed.
     *
     * @param string $to
     * @param string $subject
     * @param string $htmlBody
     * @return bool
     */
    private function dispatchEmailViaDolibarr(string $to, string $subject, string $htmlBody): bool
    {
        if (!class_exists('CMailFile')) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        }
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', '');
        if ($from === '') {
            $from = 'no-reply@' . (parse_url(OAuthConfig::getIssuer(), PHP_URL_HOST) ?: 'localhost');
        }
        $mail = new \CMailFile(
            $subject,
            $to,
            $from,
            $htmlBody,
            [],
            [],
            [],
            '',
            '',
            0,
            1,
            '',
            '',
            '',
            '',
            'mail'
        );
        if (!empty($mail->error)) {
            dol_syslog('[SmartAuth] AccountController: CMailFile init failed: ' . $mail->error, LOG_ERR);
            return false;
        }
        if (!$mail->sendfile()) {
            dol_syslog('[SmartAuth] AccountController: email send failed: ' . ($mail->error ?? ''), LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Resolve the source IP for audit purposes.
     *
     * Delegates to RouteController::get_client_ip(), which honours the
     * SMARTAUTH_TRUSTED_PROXIES allow-list.
     */
    private function getClientIp(): string
    {
        return \SmartAuth\Api\RouteController::get_client_ip();
    }

    private function actionDeleteAccount(int $userId): void
    {
        $confirm = isset($_POST['confirm']) ? trim((string) $_POST['confirm']) : '';
        if ($confirm !== 'DELETE') {
            $this->setFlash('error', 'Confirmation manquante. Saisissez DELETE pour confirmer la suppression.');
            $this->redirectToSelf();
            return;
        }

        $result = $this->registrationService->deleteSelfServiceAccount($userId);
        if ($result < 0) {
            if ($result === RegistrationService::ERR_ACCOUNT_NOT_DELETABLE) {
                $this->setFlash('error', 'Votre compte est lie a un client actif. Contactez le support pour la suppression.');
            } else {
                $this->setFlash('error', 'Suppression impossible. Reessayez plus tard.');
            }
            $this->redirectToSelf();
            return;
        }

        // Clear the session and redirect to login
        $this->sessionManager->clearSession();
        header('Location: /login');
        exit;
    }

    /**
     * Render the account page.
     *
     * @param int $userId
     */
    private function renderPage(int $userId): void
    {
        if (!class_exists('User')) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        }
        $user = new \User($this->db);
        if ($user->fetch($userId) <= 0) {
            $this->sessionManager->clearSession();
            header('Location: /login');
            exit;
        }

        $sessions = $this->accountService->listActiveSessions($userId);
        $deletable = $this->isDeletable($user);
        $extraSections = HookHelper::runAccountSectionsHook(['user_id' => $userId]);

        $csrfToken = $this->ensureCsrfToken();
        $flash = $this->consumeFlash();

        $this->renderTemplate('account', [
            'csrfToken' => $csrfToken,
            'user' => $user,
            'sessions' => $sessions,
            'deletable' => $deletable,
            'extraSections' => $extraSections,
            'flash' => $flash,
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    /**
     * Returns true if the connected user can self-delete (external user
     * linked to a prospect thirdparty without any contract).
     *
     * @param \User $user
     * @return bool
     */
    private function isDeletable(\User $user): bool
    {
        // Dolibarr User::fetch() exposes fk_soc as $this->socid; read both.
        $thirdpartyId = (int) ($user->socid ?? $user->fk_soc ?? 0);
        if ($thirdpartyId <= 0) {
            return false;
        }
        return $this->registrationService->isThirdpartyDeletableProspect($thirdpartyId);
    }

    /**
     * Validate a POSTed CSRF token against the session.
     *
     * @param string $token
     * @return bool
     */
    private function validCsrf(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $stored = $_SESSION[self::SESSION_CSRF] ?? '';
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Make sure $_SESSION has a CSRF token.
     *
     * @return string
     */
    private function ensureCsrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_CSRF]) || !is_string($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_CSRF];
    }

    /**
     * Set a flash message in session.
     *
     * @param string $type 'success' | 'error'
     * @param string $message
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'message' => $message];
    }

    /**
     * Read and clear the flash message.
     *
     * @return array{type:string,message:string}|null
     */
    private function consumeFlash(): ?array
    {
        if (empty($_SESSION[self::FLASH_KEY]) || !is_array($_SESSION[self::FLASH_KEY])) {
            return null;
        }
        $flash = $_SESSION[self::FLASH_KEY];
        unset($_SESSION[self::FLASH_KEY]);
        return [
            'type' => isset($flash['type']) ? (string) $flash['type'] : 'info',
            'message' => isset($flash['message']) ? (string) $flash['message'] : '',
        ];
    }

    /**
     * Redirect the browser to /account (PRG pattern).
     */
    private function redirectToSelf(): void
    {
        header('Location: /account', true, 303);
        exit;
    }

    /**
     * Render a template from tpl/.
     *
     * @param string $templateName
     * @param array  $vars
     */
    private function renderTemplate(string $templateName, array $vars): void
    {
        extract($vars);
        header('Content-Type: text/html; charset=utf-8');
        $path = dirname(__DIR__, 2) . '/tpl/' . $templateName . '.tpl.php';
        if (!file_exists($path)) {
            dol_syslog('[SmartAuth] AccountController: template not found: ' . $path, LOG_ERR);
            http_response_code(500);
            echo 'Template not found';
            exit;
        }
        include $path;
        exit;
    }
}
