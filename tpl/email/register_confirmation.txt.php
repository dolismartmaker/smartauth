<?php
/**
 * register_confirmation.txt.php
 *
 * Plain-text body of the registration confirmation email.
 *
 * Variables: $confirmUrl, $clientName, $clientLogoUrl, $issuer
 */
?>
Bonjour,

<?php if (!empty($clientName)): ?>
Vous venez de creer un compte pour acceder a <?= $clientName ?> via le portail
SmartAuth (<?= $issuer ?>).
<?php else: ?>
Vous venez de creer un compte sur <?= $issuer ?>.
<?php endif; ?>

Pour activer votre compte, cliquez sur le lien suivant (valable 24 heures) :

<?= $confirmUrl ?>

Si vous n'etes pas a l'origine de cette demande, ignorez ce message ; aucun
compte ne sera active sans confirmation explicite.

--
L'equipe CAP-REL
<?= $issuer ?>
