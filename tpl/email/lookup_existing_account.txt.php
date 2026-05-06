<?php
/**
 * lookup_existing_account.txt.php
 *
 * Plain-text body of the courtesy email sent by /lookup-by-email when
 * an account already exists for the requested address.
 *
 * Variables: $issuer, $loginUrl, $alternativeUrl (nullable),
 *            $login, $firstname, $lastname
 */

$displayName = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
?>
Bonjour<?= $displayName !== '' ? ' ' . $displayName : '' ?>,

Vous (ou quelqu'un) avez demande de retrouver le compte associe a cette
adresse e-mail sur <?= $issuer ?>.

Vos coordonnees :
<?php if (!empty($login)): ?>
- Identifiant : <?= $login ?>
<?php endif; ?>

Pour vous connecter, rendez-vous sur :

<?= $loginUrl ?>

<?php if (!empty($alternativeUrl)): ?>

Si vous voulez ajouter cette adresse e-mail comme alternative pour un service
specifique (ex: capTodo, capCRM), cliquez sur le lien suivant (valable 24h) :

<?= $alternativeUrl ?>
<?php endif; ?>

Si vous n'etes pas a l'origine de cette demande, ignorez ce message.

--
L'equipe CAP-REL
<?= $issuer ?>
