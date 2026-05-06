<?php
/**
 * lookup_existing_account.html.php
 *
 * HTML body of the courtesy email sent by /lookup-by-email.
 *
 * Variables: $issuer, $loginUrl, $alternativeUrl (nullable),
 *            $login, $firstname, $lastname
 */

$h = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
$displayName = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vous avez deja un compte chez nous</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;color:#333;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;padding:32px;">
                    <tr>
                        <td>
                            <h1 style="font-size:20px;margin:0 0 16px 0;color:#222;">Vous avez deja un compte chez nous</h1>
                            <p style="margin:0 0 16px 0;line-height:1.5;">
                                Bonjour<?= $displayName !== '' ? ' ' . $h($displayName) : '' ?>,
                            </p>
                            <p style="margin:0 0 16px 0;line-height:1.5;">
                                Vous (ou quelqu'un) avez demande de retrouver le compte associe a
                                cette adresse e-mail sur <strong><?= $h($issuer) ?></strong>.
                            </p>
                            <?php if (!empty($login)): ?>
                            <p style="margin:0 0 8px 0;line-height:1.5;"><strong>Vos coordonnees :</strong></p>
                            <ul style="margin:0 0 16px 0;padding-left:20px;line-height:1.5;">
                                <li>Identifiant : <strong><?= $h($login) ?></strong></li>
                            </ul>
                            <?php endif; ?>

                            <p style="margin:16px 0 24px 0;text-align:center;">
                                <a href="<?= $h($loginUrl) ?>"
                                   style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;">
                                    Se connecter
                                </a>
                            </p>

                            <?php if (!empty($alternativeUrl)): ?>
                            <p style="margin:24px 0 8px 0;line-height:1.5;font-size:14px;color:#555;">
                                Vous voulez ajouter cette adresse e-mail comme alternative pour un service specifique
                                (capTodo, capCRM, etc.) ?
                            </p>
                            <p style="margin:0 0 24px 0;text-align:center;">
                                <a href="<?= $h($alternativeUrl) ?>"
                                   style="display:inline-block;background:#6c757d;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-size:14px;">
                                    Ajouter comme alternative
                                </a>
                            </p>
                            <p style="margin:0 0 16px 0;font-size:12px;color:#888;">Lien valable 24 heures.</p>
                            <?php endif; ?>

                            <p style="margin:24px 0 0 0;font-size:13px;color:#777;line-height:1.5;">
                                Si vous n'etes pas a l'origine de cette demande, ignorez ce message.
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
