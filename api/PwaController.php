<?php

/**
 * PwaController.php
 *
 * Controller for PWA manifest and icons.
 * Serves dynamic manifest.json and icons for SmartMaker PWA apps.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api;

class PwaController
{
    /**
     * Allowed icon sizes
     */
    private const ALLOWED_SIZES = [64, 192, 512];

    /**
     * Serve dynamic PWA manifest
     * Route: GET /manifest.webmanifest
     *
     * @param array|null $payload Request payload
     * @return void
     */
    public function manifest($payload = null)
    {
        global $conf;

        $moduleName = RouteCache::getModuleName();
        if (empty($moduleName)) {
            $this->sendErrorResponse(500, 'Module not initialized');
            return;
        }

        $constPrefix = strtoupper($moduleName);

        // Get app name: custom > company name > module name
        $appName = getDolGlobalString($constPrefix . '_PWA_NAME');
        if (empty($appName)) {
            $appName = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
        }
        if (empty($appName)) {
            $appName = ucfirst($moduleName);
        }

        // Short name (max 12 chars for home screen)
        $shortName = mb_substr($appName, 0, 12);

        $manifest = [
            'name' => $appName,
            'short_name' => $shortName,
            'description' => getDolGlobalString($constPrefix . '_PWA_DESCRIPTION', ''),
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => getDolGlobalString($constPrefix . '_PWA_BG_COLOR', '#ffffff'),
            'theme_color' => getDolGlobalString($constPrefix . '_PWA_THEME_COLOR', '#000000'),
            'icons' => [
                ['src' => 'api.php/icon/64', 'sizes' => '64x64', 'type' => 'image/png'],
                ['src' => 'api.php/icon/192', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => 'api.php/icon/512', 'sizes' => '512x512', 'type' => 'image/png'],
            ]
        ];

        $this->closeDb();
        header('Content-Type: application/manifest+json');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Serve PWA icon
     * Route: GET /icon/{size}
     *
     * @param array|null $payload Request payload with 'size' parameter
     * @return void
     */
    public function icon($payload = null)
    {
        global $conf;

        $moduleName = RouteCache::getModuleName();
        if (empty($moduleName)) {
            $this->sendErrorResponse(500, 'Module not initialized');
            return;
        }

        $size = (int) ($payload['size'] ?? 512);

        // Validate size
        if (!in_array($size, self::ALLOWED_SIZES)) {
            $size = 512;
        }

        // 1. Try custom icon (uploaded via admin)
        if (!empty($conf->{$moduleName}) && !empty($conf->{$moduleName}->dir_output)) {
            $customIconPath = $conf->{$moduleName}->dir_output . '/pwa/icon_' . $size . '.png';
            if (file_exists($customIconPath)) {
                $this->sendFileResponse($customIconPath);
                return;
            }
        }

        // 2. Fallback to default icon (shipped with module via SmartBoot)
        $defaultIconPath = DOL_DOCUMENT_ROOT . '/custom/' . $moduleName . '/pwa/images/pwa-' . $size . 'x' . $size . '.png';
        if (file_exists($defaultIconPath)) {
            $this->sendFileResponse($defaultIconPath);
            return;
        }

        // 3. Last resort: generate placeholder
        $this->generatePlaceholderIcon($size, $moduleName);
    }

    /**
     * Send file response with caching headers
     *
     * @param string $filePath Path to the file
     * @return void
     */
    private function sendFileResponse(string $filePath): void
    {
        $this->closeDb();
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    /**
     * Send error response
     *
     * @param int $code HTTP status code
     * @param string $message Error message
     * @return void
     */
    private function sendErrorResponse(int $code, string $message): void
    {
        $this->closeDb();
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    /**
     * Generate a placeholder icon (blue square with text)
     *
     * @param int $size Icon size in pixels
     * @param string $moduleName Module name for initials
     * @return void
     */
    private function generatePlaceholderIcon(int $size, string $moduleName): void
    {
        $this->closeDb();

        // Check if GD is available
        if (!function_exists('imagecreatetruecolor')) {
            // Serve a 1x1 transparent PNG as fallback
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=3600');
            // Minimal 1x1 transparent PNG
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            exit;
        }

        $img = imagecreatetruecolor($size, $size);
        if ($img === false) {
            $this->sendErrorResponse(500, 'Failed to create image');
            return;
        }

        $blue = imagecolorallocate($img, 0, 119, 204);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $blue);

        // Add module initials
        $text = strtoupper(substr($moduleName, 0, 3));
        $fontSize = (int) ($size / 4);

        // Try to use a font, fallback to built-in
        $fontPath = DOL_DOCUMENT_ROOT . '/theme/common/fonts/DejaVuSans.ttf';
        if (file_exists($fontPath) && function_exists('imagettftext')) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);
            if ($bbox !== false) {
                $x = (int) (($size - ($bbox[2] - $bbox[0])) / 2);
                $y = (int) (($size + ($bbox[1] - $bbox[7])) / 2);
                imagettftext($img, $fontSize, 0, $x, $y, $white, $fontPath, $text);
            }
        } else {
            // Use built-in font as fallback
            $fontWidth = imagefontwidth(5) * strlen($text);
            $fontHeight = imagefontheight(5);
            $x = (int) (($size - $fontWidth) / 2);
            $y = (int) (($size - $fontHeight) / 2);
            imagestring($img, 5, $x, $y, $text, $white);
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    /**
     * Close database connection
     *
     * @return void
     */
    private function closeDb(): void
    {
        global $db;
        if (is_object($db)) {
            $db->close();
        }
    }
}
