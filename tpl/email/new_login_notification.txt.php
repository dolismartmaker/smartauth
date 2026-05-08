<?php
/**
 * new_login_notification.txt.php
 *
 * Plain-text version of the new-login alert email.
 */
?>
<?= $reasonText ?>


Bonjour<?php if (!empty($firstname)) { echo ' ' . $firstname; } ?>,

Une nouvelle connexion a été établie sur votre compte SmartAuth chez <?= $companyName ?>.

Identifiant   : <?= $login ?>

Date / heure  : <?= $when ?>

Adresse IP    : <?= $ip ?>

Appareil      : <?= $deviceLabel ?>


Si vous êtes à l'origine de cette connexion, ignorez ce message.

Si vous ne reconnaissez pas cette activité, ouvrez votre liste de sessions
pour révoquer le jeton concerné :

<?= $sessionsUrl ?>


--
<?= $companyName ?>

<?= $issuer ?>
