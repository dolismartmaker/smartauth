<?php

/**
 * Unit tests for the pure logic of TokenSubject (no Dolibarr DB).
 *
 * isActive() hits llx_user / llx_societe_account and is covered by the
 * Dolibarr SQLite integration suite.
 *
 * @covers \SmartAuth\Api\OAuth2\TokenSubject
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\TokenSubject;

class TokenSubjectTest extends TestCase
{
    public function testAccountFactoryAndAccessors(): void
    {
        $s = TokenSubject::account(123, 45);

        $this->assertSame(TokenSubject::TYPE_ACCOUNT, $s->getType());
        $this->assertSame(123, $s->getId());
        $this->assertSame(45, $s->getFkSoc());
        $this->assertTrue($s->isAccount());
        $this->assertFalse($s->isUser());
        $this->assertFalse($s->isInternalUser());
    }

    public function testUserFactoryExternalUser(): void
    {
        $s = TokenSubject::user(7, 99);

        $this->assertSame(TokenSubject::TYPE_USER, $s->getType());
        $this->assertSame(7, $s->getId());
        $this->assertSame(99, $s->getFkSoc());
        $this->assertTrue($s->isUser());
        $this->assertFalse($s->isAccount());
        // External user (has a societe) is NOT internal staff.
        $this->assertFalse($s->isInternalUser());
    }

    public function testUserFactoryInternalStaffDefaultsToNoSociete(): void
    {
        $s = TokenSubject::user(1);

        $this->assertSame(0, $s->getFkSoc());
        $this->assertTrue($s->isInternalUser());
    }

    public function testMemberFactoryAndAccessors(): void
    {
        $s = TokenSubject::member(55, 12);

        $this->assertSame(TokenSubject::TYPE_MEMBER, $s->getType());
        $this->assertSame(55, $s->getId());
        $this->assertSame(12, $s->getFkSoc());
        $this->assertTrue($s->isMember());
        $this->assertFalse($s->isAccount());
        $this->assertFalse($s->isUser());
        $this->assertFalse($s->isInternalUser());
    }

    public function testMemberFactoryRejectsNonPositiveId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenSubject::member(0, 1);
    }

    public function testToSubEncoding(): void
    {
        $this->assertSame('acc:123', TokenSubject::account(123, 45)->toSub());
        $this->assertSame('mbr:55', TokenSubject::member(55, 12)->toSub());
        $this->assertSame('usr:7', TokenSubject::user(7, 99)->toSub());
    }

    public function testFromSubRoundTrip(): void
    {
        $acc = TokenSubject::fromSub('acc:123');
        $this->assertTrue($acc->isAccount());
        $this->assertSame(123, $acc->getId());
        // fkSoc is not carried in the sub; resolved separately.
        $this->assertSame(0, $acc->getFkSoc());

        $mbr = TokenSubject::fromSub('mbr:55');
        $this->assertTrue($mbr->isMember());
        $this->assertSame(55, $mbr->getId());
        $this->assertSame(0, $mbr->getFkSoc());

        $usr = TokenSubject::fromSub('usr:7');
        $this->assertTrue($usr->isUser());
        $this->assertSame(7, $usr->getId());
    }

    /**
     * @dataProvider invalidSubProvider
     */
    public function testFromSubRejectsInvalid(string $sub): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenSubject::fromSub($sub);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function invalidSubProvider(): array
    {
        return [
            'legacy numeric'     => ['123'],
            'empty'              => [''],
            'unknown prefix'     => ['svc:5'],
            'prefix only'        => ['acc:'],
            'non digit id'       => ['usr:abc'],
            'member non digit'   => ['mbr:abc'],
            'member zero id'     => ['mbr:0'],
            'zero id'            => ['acc:0'],
            'negative-ish'       => ['usr:-3'],
            'trailing space'     => ['acc:12 '],
            'whitespace prefix'  => [' acc:12'],
        ];
    }

    public function testFactoriesRejectNonPositiveIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenSubject::account(0, 1);
    }

    public function testUserFactoryRejectsNonPositiveId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenSubject::user(-1);
    }

    public function testEqualsComparesTypeAndId(): void
    {
        $a = TokenSubject::account(10, 1);
        $b = TokenSubject::account(10, 2); // different societe, same identity
        $c = TokenSubject::user(10, 1);    // same id, different type
        $d = TokenSubject::account(11, 1);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertFalse($a->equals($d));
    }
}
