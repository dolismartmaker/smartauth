<?php
/**
 * email_alternative_confirmation.txt.php
 *
 * Plain-text body of the email sent when a connected user requests an
 * alternative email for a given OAuth2 client/service.
 *
 * Variables: $issuer, $confirmUrl, $serviceName, $serviceLogoUrl, $email
 */
?>
Bonjour,

Vous avez demande d'utiliser l'adresse <?= $email ?>
comme adresse alternative pour <?= $serviceName !== '' ? $serviceName : 'un service' ?> sur <?= $issuer ?>.

Pour confirmer (lien valable 24 heures) :

<?= $confirmUrl ?>

Si vous n'etes pas a l'origine de cette demande, ignorez ce message.

--
L'equipe CAP-REL
<?= $issuer ?>
