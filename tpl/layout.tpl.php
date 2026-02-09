<?php
/**
 * layout.tpl.php
 *
 * Common layout template for SmartAuth OAuth2/OIDC pages.
 * Provides consistent HTML structure, security headers, and styling.
 *
 * Usage:
 * $pageTitle = 'Page Title';
 * $pageClass = 'login-page';
 * $content = function() { ... };
 * include 'layout.tpl.php';
 *
 * Or include this file and define the content inline.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */

// Default values
$pageTitle = $pageTitle ?? 'SmartAuth';
$pageClass = $pageClass ?? '';
$issuer = $issuer ?? '';

// Security: ensure no caching of authenticated pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - SmartAuth</title>
    <link rel="stylesheet" href="/assets/css/smartauth.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
</head>
<body class="<?= htmlspecialchars($pageClass, ENT_QUOTES, 'UTF-8') ?>">
<?php
// Content will be rendered by the including template
