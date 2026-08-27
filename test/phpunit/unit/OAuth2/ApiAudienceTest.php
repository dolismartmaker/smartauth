<?php

/**
 * Unit tests for ApiAudience
 *
 * The allow-list of OAuth clients entitled to the first-party API. What these
 * tests really guard is the revocation: the constant is shared by every product
 * of the house, so removing one client must leave the others exactly where they
 * were.
 *
 * @covers \SmartAuth\Api\OAuth2\ApiAudience
 */

namespace SmartAuth\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\OAuth2\ApiAudience;

class ApiAudienceTest extends TestCase
{
    /**
     * Set the constant to a raw value, as an operator would have typed it
     */
    private function setConstant(?string $raw): void
    {
        global $conf;

        // Rebuilt rather than assumed: the suite shares one global $conf, and
        // a test running before this one may well have replaced it.
        if (!is_object($conf)) {
            $conf = new \stdClass();
        }
        if (!isset($conf->global) || !is_object($conf->global)) {
            $conf->global = new \stdClass();
        }

        if ($raw === null) {
            unset($conf->global->{ApiAudience::CONSTANT});

            return;
        }

        $conf->global->{ApiAudience::CONSTANT} = $raw;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setConstant(null);
    }

    protected function tearDown(): void
    {
        $this->setConstant(null);
        parent::tearDown();
    }

    public function testAnUnsetConstantAllowsNobody(): void
    {
        // Closed by default: this is the whole point of the S-4 audit, and an
        // empty list must never read as "everyone".
        $this->assertSame([], ApiAudience::listed());
        $this->assertFalse(ApiAudience::allows('smartauth_abc'));
        $this->assertNull(ApiAudience::expected());
    }

    public function testParseDropsBlanksAndDuplicates(): void
    {
        $this->setConstant(' smartauth_a , ,smartauth_b,smartauth_a, ');

        $this->assertSame(['smartauth_a', 'smartauth_b'], ApiAudience::listed());
    }

    public function testExpectedIsTheLoneClientAndNullBeyond(): void
    {
        // A single client is handed to the JWT validator as the expected 'aud';
        // with two of them the claim cannot single one out, and membership is
        // the only check left.
        $this->setConstant('smartauth_a');
        $this->assertSame('smartauth_a', ApiAudience::expected());

        $this->setConstant('smartauth_a,smartauth_b');
        $this->assertNull(ApiAudience::expected());
    }

    public function testGrantAddsWithoutTouchingTheOthers(): void
    {
        $this->setConstant('smartauth_a');

        $this->assertTrue(ApiAudience::grant(null, 'smartauth_b', 1));
        $this->assertSame(['smartauth_a', 'smartauth_b'], ApiAudience::listed());
    }

    public function testGrantIsIdempotent(): void
    {
        $this->setConstant('smartauth_a');

        $this->assertTrue(ApiAudience::grant(null, 'smartauth_a', 1));
        $this->assertSame(['smartauth_a'], ApiAudience::listed());
    }

    public function testGrantRefusesAnEmptyClientId(): void
    {
        $this->setConstant('smartauth_a');

        $this->assertFalse(ApiAudience::grant(null, '   ', 1));
        $this->assertSame(['smartauth_a'], ApiAudience::listed());
    }

    public function testRevokeRemovesOnlyTheClientConcerned(): void
    {
        // The constant holds every product of the house: rewriting it wholesale
        // on a revocation would cut them all off at once.
        $this->setConstant('smartauth_a,smartauth_b,smartauth_c');

        $this->assertTrue(ApiAudience::revoke(null, 'smartauth_b', 1));
        $this->assertSame(['smartauth_a', 'smartauth_c'], ApiAudience::listed());
    }

    public function testRevokingTheLastOneClosesTheGate(): void
    {
        $this->setConstant('smartauth_a');

        $this->assertTrue(ApiAudience::revoke(null, 'smartauth_a', 1));
        $this->assertSame([], ApiAudience::listed());
        $this->assertFalse(ApiAudience::allows('smartauth_a'));
    }

    public function testRevokingAClientThatIsNotListedChangesNothing(): void
    {
        $this->setConstant('smartauth_a');

        $this->assertTrue(ApiAudience::revoke(null, 'smartauth_z', 1));
        $this->assertSame(['smartauth_a'], ApiAudience::listed());
    }

    public function testAllowsIgnoresSurroundingSpaces(): void
    {
        $this->setConstant(' smartauth_a ');

        $this->assertTrue(ApiAudience::allows('smartauth_a'));
        $this->assertTrue(ApiAudience::allows(' smartauth_a '));
    }
}
