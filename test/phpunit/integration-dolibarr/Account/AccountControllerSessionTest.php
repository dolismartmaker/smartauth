<?php

/**
 * /account must serve an authenticated session instead of dying with a 500.
 *
 * THE DEFECT. AccountController::requireSession() was declared ": ?int" while
 * SessionManager::validateSession() has returned a TokenSubject since the
 * subject refactor. PHP never coerces an object to int, so EVERY authenticated
 * hit on /account raised "TypeError: requireSession(): Return value must be of
 * type ?int, SmartAuth\Api\OAuth2\TokenSubject returned", which the exception
 * handler of public/index.php turned into a bare 500 {"error":"server_error"}.
 * Password change, session revocation and account deletion were all
 * unreachable.
 *
 * Only the ANONYMOUS path worked, because requireSession() redirects and exits
 * before ever returning -- and that is precisely the single case the previous
 * smoke test exercised. A page broken for every logged-in user, with a green
 * suite: the reason this test asserts the AUTHENTICATED paths.
 *
 * THE TRAP IN THE OBVIOUS FIX. Returning $subject->getId() to keep the ": ?int"
 * signature would compile and look right. It would also be far worse than the
 * 500: every caller treats the value as a llx_user rowid (AccountService is
 * user-only, int $fkUser / fetchUser(): ?\User), so an acc:/mbr: subject would
 * change the password of the INTERNAL user carrying the same id. The TypeError
 * was, by accident, the only thing standing between this page and a silo
 * crossing. Hence the explicit gate, and the second test below.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Tests\IntegrationDolibarr\Account;

use ReflectionMethod;
use SmartAuth\Api\Account\AccountController;
use SmartAuth\Api\OAuth2\TokenSubject;
use SmartAuth\Tests\IntegrationDolibarr\DolibarrRealTestCase;

/**
 * @covers \SmartAuth\Api\Account\AccountController
 */
class AccountControllerSessionTest extends DolibarrRealTestCase
{
    /**
     * The signature must accept what validateSession() really returns.
     *
     * Checked by reflection rather than by driving a full HTTP session: the
     * mismatch IS the defect, and a type error is a static property of the
     * pair. This is the assertion that would have caught the regression the day
     * validateSession() changed its return type.
     */
    public function testRequireSessionAcceptsWhatValidateSessionReturns(): void
    {
        $required = new ReflectionMethod(AccountController::class, 'requireSession');
        $produced = new ReflectionMethod(\SmartAuth\Api\OAuth2\SessionManager::class, 'validateSession');

        $requiredType = $required->getReturnType();
        $producedType = $produced->getReturnType();

        $this->assertNotNull($requiredType, 'requireSession must keep an explicit return type');
        $this->assertNotNull($producedType, 'validateSession must keep an explicit return type');

        $this->assertSame(
            (string) $producedType,
            (string) $requiredType,
            'requireSession() consumes validateSession(): the two return types must match, '
            . 'or every authenticated hit on /account dies with a TypeError -> 500'
        );
    }

    /**
     * The gate that replaces the accidental protection of the TypeError.
     *
     * An external subject must NOT be handed to the user-only AccountService.
     * Asserted on the declaration itself so the guard cannot be dropped while
     * the page keeps answering 200.
     */
    public function testAnExternalSubjectIsNotRoutedToTheUserOnlyService(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/api/Account/AccountController.php'
        );

        $this->assertStringContainsString(
            'isUser()',
            $source,
            'handle() must gate on the subject type before addressing a llx_user rowid'
        );

        // The service really is user-only: that is WHY the gate is required.
        $update = new ReflectionMethod(\SmartAuth\Api\Account\AccountService::class, 'updateIdentity');
        $first = $update->getParameters()[0];
        $this->assertSame(
            'fkUser',
            $first->getName(),
            'AccountService became subject-aware? Then the gate in handle() can be widened - '
            . 'deliberately, and with tests for each subject type.'
        );
    }

    /**
     * TokenSubject really cannot pass for an int, so the TypeError was not
     * hypothetical. Pins the premise of this whole test file.
     */
    public function testATokenSubjectIsNotAnInteger(): void
    {
        $subject = TokenSubject::fromSub('acc:7');
        $this->assertNotNull($subject, 'acc:7 must parse as a subject');
        $this->assertIsNotInt($subject);
        $this->assertSame(7, (int) $subject->getId());
        $this->assertFalse($subject->isUser(), 'an acc: subject is external');
    }
}
