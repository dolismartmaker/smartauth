<?php
/**
 * register_confirmation.html.php
 *
 * HTML body of the registration confirmation email.
 *
 * Variables: $confirmUrl, $clientName, $clientLogoUrl, $issuer
 */

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Confirmez votre adresse e-mail</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;color:#333;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;padding:32px;">
                    <?php if (!empty($clientLogoUrl)): ?>
                    <tr>
                        <td align="center" style="padding-bottom:16px;">
                            <img src="<?= $h($clientLogoUrl) ?>" alt="<?= $h($clientName) ?>" style="max-height:64px;">
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>
                            <h1 style="font-size:20px;margin:0 0 16px 0;color:#222;">Confirmez votre adresse e-mail</h1>
                            <p style="margin:0 0 16px 0;line-height:1.5;">Bonjour,</p>
                            <p style="margin:0 0 16px 0;line-height:1.5;">
                                <?php if (!empty($clientName)): ?>
                                Vous venez de creer un compte pour acceder a <strong><?= $h($clientName) ?></strong>
                                via le portail SmartAuth.
                                <?php else: ?>
                                Vous venez de creer un compte sur <strong><?= $h($issuer) ?></strong>.
                                <?php endif; ?>
                            </p>
                            <p style="margin:0 0 24px 0;line-height:1.5;">
                                Pour activer votre compte, cliquez sur le bouton ci-dessous (lien valable 24 heures).
                            </p>
                            <p style="margin:0 0 24px 0;text-align:center;">
                                <a href="<?= $h($confirmUrl) ?>"
                                   style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;">
                                    Confirmer mon adresse e-mail
                                </a>
                            </p>
                            <p style="margin:0 0 16px 0;font-size:13px;color:#555;line-height:1.5;">
                                Si le bouton ne fonctionne pas, copiez-collez ce lien dans votre navigateur :
                                <br>
                                <a href="<?= $h($confirmUrl) ?>" style="color:#0d6efd;word-break:break-all;"><?= $h($confirmUrl) ?></a>
                            </p>
                            <p style="margin:24px 0 0 0;font-size:13px;color:#777;line-height:1.5;">
                                Si vous n'etes pas a l'origine de cette demande, ignorez ce message.
                                Aucun compte ne sera active sans confirmation explicite.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top:24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                            <em>L'equipe CAP-REL</em><br>
                            <?= $h($issuer) ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
