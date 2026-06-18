<?php

/**
 * EmailAlternativeController.php
 *
 * HTTP entry point for the /email-alternative/confirm route (Lot 9 SmartAuth).
 *
 * The user clicks a link sent by email. The token (purpose=email_change) is
 * validated and consumed here, then SmartAuth fires the
 * `smartmaker_email_alternative_persist` hook so ssomanager can insert the
 * row into `llx_ssomanager_user_service_email`. If no module owns the hook,
 * the page surfaces a "configuration missing" error rather than silently
 * dropping the request.
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

dol_include_once('/smartauth/api/OAuth2/HookHelper.php');

class EmailAlternativeController
{
    /**
     * @var \DoliDB
     */
    private $db;

    /**
     * @var EmailValidationToken
     */
    private $tokens;

    public function __construct($db, ?EmailValidationToken $tokens = null)
    {
        $this->db = $db;
        $this->tokens = $tokens ?? new EmailValidationToken($db);
    }

    /**
     * Handle GET /email-alternative/confirm?token=...
     *
     * @return void
     */
    public function handleConfirm(): void
    {
        $plainToken = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
        if ($plainToken === '') {
            $this->renderInvalid();
            return;
        }

        $row = $this->tokens->findActive(
            EmailValidationToken::hashToken($plainToken),
            EmailValidationToken::PURPOSE_EMAIL_CHANGE
        );
        if ($row === null) {
            dol_syslog('[SmartAuth] EmailAlternativeController: token not found / expired / used', LOG_INFO);
            $this->renderInvalid();
            return;
        }

        $userId = (int) $row['fk_user'];
        if ($userId <= 0) {
            $this->renderInvalid();
            return;
        }

        $context = $this->decodeContext($row['context'] ?? null);
        $email = isset($context['email']) ? (string) $context['email'] : '';
        $clientPk = isset($context['client_pk']) ? (int) $context['client_pk'] : null;
        $clientId = isset($context['client_id']) ? (string) $context['client_id'] : null;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Cases like /lookup-by-email tokens emit an email_change token
            // without an email/client context: treat as invalid here. The
            // user must request the alternative email from /account first.
            dol_syslog('[SmartAuth] EmailAlternativeController: token lacks email context', LOG_INFO);
            $this->renderInvalid();
            return;
        }

        // Consume the token *before* delegating, so a failure of the
        // external module cannot be replayed.
        if (!$this->tokens->markUsed((int) $row['rowid'])) {
            dol_syslog('[SmartAuth] EmailAlternativeController: failed to mark token used', LOG_ERR);
            $this->renderInternalError();
            return;
        }

        $hookResult = HookHelper::runEmailAlternativePersistHook([
            'user_id' => $userId,
            'client_pk' => $clientPk,
            'client_id' => $clientId,
            'email' => $email,
        ]);

        if ($hookResult['internal_error']) {
            $this->renderInternalError();
            return;
        }
        if (!$hookResult['handled']) {
            dol_syslog('[SmartAuth] EmailAlternativeController: no module handled smartmaker_email_alternative_persist', LOG_WARNING);
            $this->renderConfigurationMissing();
            return;
        }

        $this->renderConfirmed($email, $hookResult['service']);
    }

    /**
     * Decode the JSON context payload stored alongside the token.
     *
     * @param mixed $context
     * @return array<string, mixed>
     */
    private function decodeContext($context): array
    {
        if (!is_string($context) || $context === '') {
            return [];
        }
        $decoded = json_decode($context, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private function renderInvalid(): void
    {
        $this->renderTemplate('email_alternative_invalid', [
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    private function renderInternalError(): void
    {
        http_response_code(500);
        $this->renderTemplate('error', [
            'error' => 'server_error',
            'errorDescription' => 'Une erreur est survenue lors de l\'enregistrement de l\'adresse alternative.',
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    private function renderConfigurationMissing(): void
    {
        http_response_code(503);
        $this->renderTemplate('error', [
            'error' => 'service_unavailable',
            'errorDescription' => 'Cette fonctionnalite necessite l\'activation du module ssomanager. Contactez votre administrateur.',
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    private function renderConfirmed(string $email, ?string $service): void
    {
        $this->renderTemplate('email_alternative_confirmed', [
            'email' => $email,
            'service' => $service,
            'issuer' => OAuthConfig::getIssuer(),
        ]);
    }

    /**
     * Render a tpl/ template.
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
            dol_syslog('[SmartAuth] EmailAlternativeController: template not found: ' . $path, LOG_ERR);
            http_response_code(500);
            echo 'Template not found';
            exit;
        }
        include $path;
        exit;
    }
}
