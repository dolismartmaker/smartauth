<?php
/**
 * new_login_notification.html.php
 *
 * HTML body of the "new login alert" email.
 *
 * Variables:
 *   $issuer, $sessionsUrl, $login, $firstname, $lastname,
 *   $ip, $deviceLabel, $reason, $reasonText, $when, $companyName
 */

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title><?= $h($companyName) ?></title></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;color:#333;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="font-size:20px;margin:0 0 16px 0;color:#b91c1c;"><?= $h($reasonText) ?></h1>

<p style="margin:0 0 16px 0;line-height:1.5;">Bonjour<?php if ($firstname) {
    echo ' ' . $h($firstname);
} ?>,</p>

<p style="margin:0 0 16px 0;line-height:1.5;">
Une nouvelle connexion a été établie sur votre compte SmartAuth chez <strong><?= $h($companyName) ?></strong>. Détails ci-dessous :
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;font-size:14px;background:#f9fafb;border-radius:6px;padding:12px;width:100%;">
<tr><td style="padding:6px 12px;color:#6b7280;width:140px;">Identifiant</td><td style="padding:6px 12px;"><strong><?= $h($login) ?></strong></td></tr>
<tr><td style="padding:6px 12px;color:#6b7280;">Date / heure</td><td style="padding:6px 12px;"><?= $h($when) ?></td></tr>
<tr><td style="padding:6px 12px;color:#6b7280;">Adresse IP</td><td style="padding:6px 12px;font-family:monospace;"><?= $h($ip) ?></td></tr>
<tr><td style="padding:6px 12px;color:#6b7280;">Appareil</td><td style="padding:6px 12px;"><?= $h($deviceLabel) ?></td></tr>
</table>

<p style="margin:16px 0;line-height:1.5;">
Si <strong>vous êtes à l'origine</strong> de cette connexion, vous pouvez ignorer ce message.
</p>

<p style="margin:16px 0;line-height:1.5;">
Si vous ne reconnaissez pas cette activité, ouvrez votre liste de sessions pour révoquer immédiatement le jeton concerné :
</p>

<p style="margin:0 0 24px 0;text-align:center;">
<a href="<?= $h($sessionsUrl) ?>" style="display:inline-block;background:#b91c1c;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;">
Vérifier mes sessions actives
</a>
</p>

<p style="margin:24px 0 0 0;font-size:12px;color:#777;line-height:1.5;">
Vous recevez ce message parce que la notification de nouvelle connexion est activée sur votre compte SmartAuth. Pour la désactiver, contactez votre administrateur.
</p>
</td></tr>
<tr><td style="padding-top:24px;border-top:1px solid #eee;font-size:12px;color:#888;">
<em><?= $h($companyName) ?></em><br><?= $h($issuer) ?>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
