<?php

/**
 * End-to-end issuance for a `member` subject (an association member portal
 * account, llx_adherent).
 *
 * Proves that TokenService, fed a member TokenSubject:
 *   - stamps the access/id token `sub` as "mbr:<rowid>";
 *   - sources OIDC identity claims from the adherent (email + personal name);
 *   - persists subject_type='member' / fk_adherent / fk_user=0 on the stored
 *     token row (fk_societe_account stays NULL);
 *   - and that TokenSubject::fromRecord rebuilds the same subject from it.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenService
 * @covers \SmartAuth\Api\OAuth2\TokenSubject
 */

namespace SmartAuth\Tests\IntegrationDolibarr\OAuth2;

use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;
use SmartAuth\Api\OAuth2\TokenService;
use SmartAuth\Api\OAuth2\TokenSubject;

dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');
dol_include_once('/smartauth/api/OAuth2/TokenService.php');
dol_include_once('/smartauth/api/OAuth2/TokenSubject.php');

class MemberTokenIssuanceTest extends DolibarrRealTestCase
{
    /** @var TokenService */
    private $tokenService;

    protected function setUp(): void
    {
        parent::setUp();
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        $this->tokenService = new TokenService($this->db);
    }

    public function testAccessTokenSubIsPrefixedMbr(): void
    {
        $adherentId = $this->createAdherent('Bob', 'Durand', 'bob.durand@example.com');
        $subject = TokenSubject::member($adherentId, 0);

        $at = $this->tokenService->createAccessToken(0, 'demo-client', ['openid'], 3600, [], $subject);
        $payload = $this->decodeJwtPayload($at['token']);

        $this->assertSame('mbr:' . $adherentId, $payload['sub']);
    }

    public function testIdTokenCarriesMemberIdentityClaims(): void
    {
        $adherentId = $this->createAdherent('Bob', 'Durand', 'bob.durand@example.com');
        $subject = TokenSubject::member($adherentId, 0);

        $idt = $this->tokenService->createIdToken(0, 'demo-client', ['openid', 'profile', 'email'], null, time(), null, $subject);
        $payload = $this->decodeJwtPayload($idt);

        $this->assertSame('mbr:' . $adherentId, $payload['sub']);
        $this->assertSame('bob.durand@example.com', $payload['email']);
        $this->assertTrue($payload['email_verified']);
        $this->assertSame('Bob Durand', $payload['name']);
        // Unlike a company account, a member exposes a personal name.
        $this->assertSame('Bob', $payload['given_name']);
        $this->assertSame('Durand', $payload['family_name']);
        // A member is not a Dolibarr backend user: no groups/roles.
        $this->assertArrayNotHasKey('groups', $payload);
        $this->assertArrayNotHasKey('roles', $payload);
    }

    public function testStoredTokenRowCarriesMemberSubjectColumns(): void
    {
        $adherentId = $this->createAdherent('Carl', 'Petit', 'carl.petit@example.com');
        $subject = TokenSubject::member($adherentId, 0);

        $jti = \SmartAuthOAuthToken::generateJti();
        $row = $this->tokenService->storeAccessToken($jti, 999, 0, ['openid'], time() + 3600, null, $subject);

        $sql = 'SELECT subject_type, fk_user, fk_societe_account, fk_adherent FROM ' . MAIN_DB_PREFIX . 'smartauth_oauth_tokens';
        $sql .= ' WHERE rowid = ' . (int) $row->id;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $this->assertSame('member', $obj->subject_type);
        $this->assertSame(0, (int) $obj->fk_user);
        $this->assertTrue($obj->fk_societe_account === null || (int) $obj->fk_societe_account === 0);
        $this->assertSame($adherentId, (int) $obj->fk_adherent);

        // fromRecord must rebuild the same subject.
        $rebuilt = TokenSubject::fromRecord(
            $this->db,
            $obj->subject_type,
            (int) $obj->fk_user,
            $obj->fk_societe_account !== null ? (int) $obj->fk_societe_account : null,
            (int) $obj->fk_adherent
        );
        $this->assertTrue($rebuilt->isMember());
        $this->assertSame($adherentId, $rebuilt->getId());
    }

    private function createAdherent(string $firstname, string $lastname, string $email): int
    {
        $uniq = uniqid();
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'adherent';
        $sql .= ' (ref, entity, fk_adherent_type, morphy, statut, login, email, firstname, lastname, datec)';
        $sql .= " VALUES ('MBR_" . $uniq . "', 1, 1, 'phy', 1,";
        $sql .= " 'member_" . $uniq . "', '" . $this->db->escape($email) . "',";
        $sql .= " '" . $this->db->escape($firstname) . "', '" . $this->db->escape($lastname) . "', '" . $now . "')";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert adherent: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'adherent');
    }

    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have three segments');
        $json = base64_decode(strtr($parts[1], '-_', '+/'));
        $payload = json_decode($json, true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
