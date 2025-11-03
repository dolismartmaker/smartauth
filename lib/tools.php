<?php
/* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Parse User-Agent to get device information
 *
 * @param string $userAgent User agent string
 * @return array Device info with icon, name, os
 */
function parseDeviceInfo($userAgent)
{
    $device = [
        'icon' => '💻',
        'name' => 'Unknown Device',
        'os' => '',
        'browser' => ''
    ];

    if (empty($userAgent)) {
        return $device;
    }

    // Detect mobile devices
    if (preg_match('/iPhone/i', $userAgent)) {
        $device['icon'] = '📱';
        $device['name'] = 'iPhone';
        if (preg_match('/OS (\d+)_(\d+)/i', $userAgent, $matches)) {
            $device['os'] = 'iOS ' . $matches[1] . '.' . $matches[2];
        }
    } elseif (preg_match('/iPad/i', $userAgent)) {
        $device['icon'] = '📱';
        $device['name'] = 'iPad';
        if (preg_match('/OS (\d+)_(\d+)/i', $userAgent, $matches)) {
            $device['os'] = 'iOS ' . $matches[1] . '.' . $matches[2];
        }
    } elseif (preg_match('/Android/i', $userAgent)) {
        $device['icon'] = '📱';
        $device['name'] = 'Android';
        if (preg_match('/Android (\d+\.?\d*)/i', $userAgent, $matches)) {
            $device['os'] = 'Android ' . $matches[1];
        }
        // Try to get device model
        if (preg_match('/\(([^)]+)\)/', $userAgent, $matches)) {
            $parts = explode(';', $matches[1]);
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/^[A-Z]/', $part) && !preg_match('/Android|Linux|Build/', $part)) {
                    $device['name'] = substr($part, 0, 30);
                    break;
                }
            }
        }
    }

    // Detect OS for desktop
    if (empty($device['os'])) {
        if (preg_match('/Windows NT (\d+\.\d+)/i', $userAgent, $matches)) {
            $device['icon'] = '💻';
            $versions = [
                '10.0' => 'Windows 11/10',
                '6.3' => 'Windows 8.1',
                '6.2' => 'Windows 8',
                '6.1' => 'Windows 7'
            ];
            $device['os'] = $versions[$matches[1]] ?? 'Windows';
        } elseif (preg_match('/Mac OS X (\d+)[_\.](\d+)/i', $userAgent, $matches)) {
            $device['icon'] = '💻';
            $device['name'] = 'Mac';
            $device['os'] = 'macOS ' . $matches[1] . '.' . $matches[2];
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $device['icon'] = '💻';
            $device['os'] = 'Linux';
        }
    }

    // Detect browser
    if (preg_match('/Chrome\/(\d+)/i', $userAgent, $matches)) {
        $device['browser'] = 'Chrome ' . $matches[1];
    } elseif (preg_match('/Firefox\/(\d+)/i', $userAgent, $matches)) {
        $device['browser'] = 'Firefox ' . $matches[1];
    } elseif (preg_match('/Safari\/(\d+)/i', $userAgent, $matches)) {
        if (!preg_match('/Chrome/i', $userAgent)) {
            $device['browser'] = 'Safari';
        }
    } elseif (preg_match('/Edg\/(\d+)/i', $userAgent, $matches)) {
        $device['browser'] = 'Edge ' . $matches[1];
    }

    return $device;
}


/**
 * Get country flag emoji and name from IP
 *
 * @param string $ip IP address
 * @return array Country info with flag and name
 */
function getCountryFromIP($ip)
{
    global $db;

    $country = [
        'flag' => '🌐',
        'code' => '',
        'name' => 'Unknown'
    ];

    if (empty($ip) || $ip == '127.0.0.1' || preg_match('/^192\.168\.|^10\.|^172\.(1[6-9]|2\d|3[01])\./', $ip)) {
        $country['name'] = 'Local Network';
        return $country;
    }

    $countryCode = dolGetCountryCodeFromIp($ip);
    if ($countryCode && strlen($countryCode) == 2) {
        $country['code'] = strtoupper($countryCode);
        $country['flag'] = getCountryFlag($countryCode);

        // Get country name from Dolibarr
        include_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
        $country['name'] = getCountry($countryCode, 1, $db);
    }
    return $country;
}

/**
 * Get country flag emoji from country code
 *
 * @param string $countryCode ISO 2-letter country code
 * @return string Flag emoji
 */
function getCountryFlag($countryCode)
{
    if (empty($countryCode) || strlen($countryCode) != 2) {
        return '🌐';
    }

    $countryCode = strtoupper($countryCode);

    // Convert country code to flag emoji
    // Each letter is converted to Regional Indicator Symbol
    $flag = '';
    for ($i = 0; $i < 2; $i++) {
        $flag .= mb_chr(0x1F1E6 + ord($countryCode[$i]) - ord('A'));
    }

    return $flag;
}

/**
 * Get relative time string
 *
 * @param int $timestamp Unix timestamp
 * @return string Relative time (e.g., "2 minutes ago")
 */
function getRelativeTime($timestamp)
{
    global $langs;

    if (empty($timestamp)) {
        return '-';
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return '<span style="color: #10b981; font-weight: bold;">' . $langs->trans("ActiveNow") . '</span>';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' ' . $langs->trans("minutes");
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' ' . $langs->trans("hours");
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' ' . $langs->trans("days");
    } else {
        return dol_print_date($timestamp, 'day');
    }
}

/**
 * Get token type badge
 *
 * @param string $type Token type (access/refresh)
 * @return string HTML badge
 */
function getTokenTypeBadge($type)
{
    global $langs;

    if ($type == 'refresh') {
        return '<span class="badge badge-status4" style="background: #8b5cf6; color: white;">♻️ ' . $langs->trans("Refresh") . '</span>';
    } else {
        return '<span class="badge badge-status4" style="background: #3b82f6; color: white;">🔑 ' . $langs->trans("Access") . '</span>';
    }
}
