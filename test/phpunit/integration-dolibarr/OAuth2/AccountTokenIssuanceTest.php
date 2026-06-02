<?php

/**
 * End-to-end issuance for an `account` subject (a billed client's portal
 * account, llx_societe_account) - the goal of the subject cutover.
 *
 * Proves that TokenService, fed an account TokenSubject:
 *   - stamps the access/id token `sub` as "acc:<rowid>";
 *   - sources OIDC identity claims from the account (email = login, name =
 *     owning societe raison sociale);
 *   - persists subject_type='account' / fk_societe_account / fk_user=0 on the
 *     stored token row;
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

class AccountTokenIssuanceTest extends DolibarrRealTestCase
{
    /** @var TokenService */
    private $tokenService;

    protected function setUp(): void
    {
        parent::setUp();
        global $conf;
        $conf->global->SMARTAUTH_OAUTH_ISSUER = 'https://auth.test.example.com';
        $conf->global->SMARTAUTH_DEFAULT_USER = 1;
        $conf->global->SOCIETE_CODECLIENT_ADDON = 'mod_codeclient_monkey';
        $conf->global->SOCIETE_CODEFOURNISSEUR_ADDON = 'mod_codefournisseur_panda';
        $this->tokenService = new TokenService($this->db);
    }

    public function testAccessTokenSubIsPrefixedAcc(): void
    {
        $soc = $this->createTestSociete(['name' => 'ACME Corp']);
        $accountId = $this->createPortalAccount('portal@acme.example', (int) $soc->id);
        $subject = TokenSubject::account($accountId, (int) $soc->id);

        $at = $this->tokenService->createAccessToken(0, 'demo-client', ['openid'], 3600, [], $subject);
        $payload = $this->decodeJwtPayload($at['token']);

        $this->assertSame('acc:' . $accountId, $payload['sub']);
    }

    public function testIdTokenCarriesAccountIdentityClaims(): void
    {
        $soc = $this->createTestSociete(['name' => 'ACME Corp']);
        $accountId = $this->createPortalAccount('portal2@acme.example', (int) $soc->id);
        $subject = TokenSubject::account($accountId, (int) $soc->id);

        $idt = $this->tokenService->createIdToken(0, 'demo-client', ['openid', 'profile', 'email'], null, time(), null, $subject);
        $payload = $this->decodeJwtPayload($idt);

        $this->assertSame('acc:' . $accountId, $payload['sub']);
        $this->assertSame('portal2@acme.example', $payload['email']);
        $this->assertTrue($payload['email_verified']);
        $this->assertSame('ACME Corp', $payload['name']);
        // A portal account is not a Dolibarr backend user: no groups/roles.
        $this->assertArrayNotHasKey('groups', $payload);
        $this->assertArrayNotHasKey('roles', $payload);
    }

    public function testStoredTokenRowCarriesSubjectColumns(): void
    {
        $soc = $this->createTestSociete();
        $accountId = $this->createPortalAccount('portal3@acme.example', (int) $soc->id);
        $subject = TokenSubject::account($accountId, (int) $soc->id);

        $jti = \SmartAuthOAuthToken::generateJti();
        $row = $this->tokenService->storeAccessToken($jti, 999, 0, ['openid'], time() + 3600, null, $subject);

        $sql = 'SELECT subject_type, fk_user, fk_societe_account FROM ' . MAIN_DB_PREFIX . 'smartauth_oauth_tokens';
        $sql .= ' WHERE rowid = ' . (int) $row->id;
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $this->assertSame('account', $obj->subject_type);
        $this->assertSame(0, (int) $obj->fk_user);
        $this->assertSame($accountId, (int) $obj->fk_societe_account);

        // fromRecord must rebuild the same subject (resolving fkSoc).
        $rebuilt = TokenSubject::fromRecord($this->db, $obj->subject_type, (int) $obj->fk_user, (int) $obj->fk_societe_account);
        $this->assertTrue($rebuilt->isAccount());
        $this->assertSame($accountId, $rebuilt->getId());
        $this->assertSame((int) $soc->id, $rebuilt->getFkSoc());
    }

    private function createPortalAccount(string $login, int $fkSoc): int
    {
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'societe_account';
        $sql .= ' (entity, login, fk_soc, site, status, fk_user_creat, date_creation)';
        $sql .= " VALUES (1, '" . $this->db->escape($login) . "', " . (int) $fkSoc . ",";
        $sql .= " 'smartauth', 1, " . (int) $this->testUser->id . ", '" . $now . "')";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new \Exception('Failed to insert societe_account: ' . $this->db->lasterror());
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'societe_account');
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
