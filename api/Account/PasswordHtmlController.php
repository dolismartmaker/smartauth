<?php

/**
 * PasswordHtmlController.php
 *
 * HTML companion of the PasswordResetController JSON API. Renders the
 * two pages of the password reset flow on the OAuth2 SSO portal:
 *
 *   GET  /forgot-password         -> email entry form
 *   POST /forgot-password         -> calls PasswordResetController::requestReset()
 *                                    and re-renders the form with a
 *                                    generic "if the email exists, we sent
 *                                    a link" message (anti-enumeration)
 *   GET  /reset-password?token=&email= -> new-password form (token + email
 *                                    pre-filled from the query string)
 *   POST /reset-password          -> calls PasswordResetController::confirmReset()
 *                                    and re-renders with a success page
 *                                    or with the inline error message
 *
 * The link the user receives by email already points at
 *   {SMARTAUTH_APP_URL}/reset-password?token=XYZ&email=foo@bar
 * (see PasswordResetController::sendResetEmail()). Setting SMARTAUTH_APP_URL
 * to the OAuth2 portal vhost is what plugs everything together.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

dol_include_once('/smartauth/api/PasswordResetController.php');

use SmartAuth\Api\PasswordResetController;

class PasswordHtmlController
{
    /**
     * @var PasswordResetController|null Injected for testing.
     */
    private $injectedBackend;

    public function __construct(?PasswordResetController $backend = null)
    {
        $this->injectedBackend = $backend;
    }

    /**
     * GET /forgot-password
     */
    public function handleForgotGet(array $params = []): void
    {
        $this->renderForgot([
            'email' => isset($params['email']) ? (string) $params['email'] : '',
            'sent' => false,
            'error' => null,
        ]);
    }

    /**
     * POST /forgot-password
     *
     * Always re-renders the same template with the generic
     * "check your inbox" message, regardless of whether the email
     * matched a real user. This mirrors PasswordResetController::requestReset
     * which already swallows the not-found case for anti-enumeration; the
     * extra HTML-side guard is so that even a 500 (which the JSON API
     * surfaces) does not leak that the rate limiter or the SMTP relay
     * misbehaved for THIS email.
     */
    public function handleForgotPost(array $params = []): void
    {
        $email = isset($params['email']) ? trim((string) $params['email']) : '';

        // Only surface the inline error for invalid syntax. Everything
        // else (rate limit, SMTP failure, user not found) collapses to
        // the same generic "if your email exists..." success page.
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderForgot([
                'email' => $email,
                'sent' => false,
                'error' => 'invalid_email',
            ]);
            return;
        }

        $backend = $this->resolveBackend();
        $backend->requestReset(['email' => $email]);

        $this->renderForgot([
            'email' => '',
            'sent' => true,
            'error' => null,
        ]);
    }

    /**
     * GET /reset-password?token=XYZ&email=foo@bar
     */
    public function handleResetGet(array $params = []): void
    {
        $this->renderReset([
            'email' => isset($params['email']) ? (string) $params['email'] : '',
            'token' => isset($params['token']) ? (string) $params['token'] : '',
            'done' => false,
            'error' => null,
        ]);
    }

    /**
     * POST /reset-password
     */
    public function handleResetPost(array $params = []): void
    {
        $email = isset($params['email']) ? trim((string) $params['email']) : '';
        $token = isset($params['token']) ? trim((string) $params['token']) : '';
        $password = isset($params['password']) ? (string) $params['password'] : '';
        $confirm = isset($params['password_confirm']) ? (string) $params['password_confirm'] : '';

        if ($password !== $confirm) {
            $this->renderReset([
                'email' => $email,
                'token' => $token,
                'done' => false,
                'error' => 'password_mismatch',
            ]);
            return;
        }

        $backend = $this->resolveBackend();
        $result = $backend->confirmReset([
            'email' => $email,
            'token' => $token,
            'password' => $password,
        ]);

        // confirmReset returns [$body, $httpStatus]. 200 = success.
        $status = is_array($result) && isset($result[1]) ? (int) $result[1] : 500;
        if ($status >= 200 && $status < 300) {
            $this->renderReset([
                'email' => '',
                'token' => '',
                'done' => true,
                'error' => null,
            ]);
            return;
        }

        // Map the backend's error code to a UI-friendly bucket. The
        // template handles the rendering; we just classify.
        $errorCode = 'reset_failed';
        if ($status === 410) {
            $errorCode = 'token_expired';
        } elseif ($status === 400) {
            $errorCode = 'invalid_input';
        } elseif ($status === 429) {
            $errorCode = 'rate_limited';
        }

        $this->renderReset([
            'email' => $email,
            'token' => $token,
            'done' => false,
            'error' => $errorCode,
        ]);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderForgot(array $vars): void
    {
        $vars['pageTitle'] = 'Mot de passe oublié';
        $vars['pageClass'] = 'forgot-password-page login-page';
        $this->renderTemplate('password-forgot', $vars);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderReset(array $vars): void
    {
        $vars['pageTitle'] = 'Nouveau mot de passe';
        $vars['pageClass'] = 'reset-password-page login-page';
        $this->renderTemplate('password-reset', $vars);
    }

    /**
     * Render one of the tpl/ templates with the given variables.
     * @param array<string,mixed> $vars
     */
    private function renderTemplate(string $name, array $vars): void
    {
        $templatePath = dirname(__DIR__, 2) . '/tpl/' . $name . '.tpl.php';
        if (!is_file($templatePath)) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'error' => 'server_error',
                'error_description' => "Template missing: $name",
            ]);
            return;
        }
        extract($vars, EXTR_OVERWRITE);
        include $templatePath;
    }

    private function resolveBackend(): PasswordResetController
    {
        if ($this->injectedBackend !== null) {
            return $this->injectedBackend;
        }
        return new PasswordResetController();
    }
}
