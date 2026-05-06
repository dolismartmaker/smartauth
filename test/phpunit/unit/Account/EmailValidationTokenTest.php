<?php

/**
 * Unit tests for EmailValidationToken (sha256 hashing, lookup, single-use).
 *
 * @covers \SmartAuth\Api\Account\EmailValidationToken
 */

namespace SmartAuth\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\Account\EmailValidationToken;
use SmartAuth\Tests\Mocks\MockDatabase;

class EmailValidationTokenTest extends TestCase
{
    public function testGenerateAndHashAreDeterministic(): void
    {
        $plain = EmailValidationToken::generatePlainToken();

        $this->assertNotEmpty($plain);
        $this->assertGreaterThan(20, strlen($plain), 'Token should be base64url of >=32 random bytes');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $plain);

        $hash = EmailValidationToken::hashToken($plain);
        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, hash('sha256', $plain));
        $this->assertNotSame($hash, EmailValidationToken::hashToken($plain . 'tampered'));
    }

    public function testCreateInsertsHashedTokenAndReturnsRowId(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true)->setLastInsertId(99);

        $tokens = new EmailValidationToken($db);
        $rowId = $tokens->create(
            42,
            EmailValidationToken::PURPOSE_REGISTER,
            EmailValidationToken::hashToken('plain-token'),
            86400,
            '203.0.113.7',
            ['continue' => 'https://app.example.com/cb']
        );

        $this->assertSame(99, $rowId);
        $this->assertTrue($db->hasQueryContaining('INSERT INTO ' . MAIN_DB_PREFIX . 'smartauth_email_validation'));
        $this->assertTrue($db->hasQueryContaining('register'));
        $this->assertTrue($db->hasQueryContaining('203.0.113.7'));
        // The plain token must NEVER hit the SQL.
        $this->assertFalse($db->hasQueryContaining('plain-token'));
    }

    public function testCreateReturnsMinusOneOnFailure(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(false);

        $tokens = new EmailValidationToken($db);
        $rowId = $tokens->create(
            1,
            EmailValidationToken::PURPOSE_REGISTER,
            'hash',
            86400,
            '127.0.0.1'
        );

        $this->assertSame(-1, $rowId);
    }

    public function testFindActiveReturnsRowWhenHashMatchesAndStillValid(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true, [[
            'rowid' => 7,
            'token_hash' => 'hash',
            'fk_user' => 42,
            'purpose' => 'register',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'used_at' => null,
            'context' => '{"continue":"https://app.example.com/cb"}',
            'entity' => 1,
        ]]);

        $tokens = new EmailValidationToken($db);
        $row = $tokens->findActive('hash', 'register');

        $this->assertNotNull($row);
        $this->assertSame(7, $row['rowid']);
        $this->assertSame(42, $row['fk_user']);
        $this->assertSame('register', $row['purpose']);
        $this->assertNull($row['used_at']);
    }

    public function testFindActiveReturnsNullWhenNotFound(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true, []);

        $tokens = new EmailValidationToken($db);
        $this->assertNull($tokens->findActive('hash', 'register'));
    }

    public function testFindActiveScopesQueryByPurposeAndFreshness(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true, []);

        $tokens = new EmailValidationToken($db);
        $tokens->findActive('hash', 'register');

        $sql = $db->getLastQuery();
        $this->assertNotNull($sql);
        $this->assertStringContainsString("purpose = 'register'", $sql);
        $this->assertStringContainsString('used_at IS NULL', $sql);
        $this->assertStringContainsString("expires_at >", $sql);
    }

    public function testMarkUsedExecutesScopedUpdate(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true);

        $tokens = new EmailValidationToken($db);
        $this->assertTrue($tokens->markUsed(7));

        $sql = $db->getLastQuery();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('UPDATE ' . MAIN_DB_PREFIX . 'smartauth_email_validation', $sql);
        $this->assertStringContainsString('rowid = 7', $sql);
        $this->assertStringContainsString('used_at IS NULL', $sql);
    }

    public function testInvalidateActiveForUserReturnsAffectedRows(): void
    {
        $db = new MockDatabase();
        $db->setQueryResult(true)->setAffectedRows(3);

        $tokens = new EmailValidationToken($db);
        $count = $tokens->invalidateActiveForUser(42, 'register');

        $this->assertSame(3, $count);
        $this->assertTrue($db->hasQueryContaining('fk_user = 42'));
        $this->assertTrue($db->hasQueryContaining("purpose = 'register'"));
    }
}
