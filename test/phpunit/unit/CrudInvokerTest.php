<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\CrudInvoker;

// --- Fake Dolibarr-like objects reproducing the divergent CRUD signatures ---

class FakeIdFirstUpdate
{
    public $id = 42;
    public $lastCall = null;
    public function update($id, $user = null, $call_trigger = 1)
    {
        $this->lastCall = ['id' => $id, 'user' => $user, 'trigger' => $call_trigger];
        return 1;
    }
}

class FakeUserFirstUpdate
{
    public $id = 7;
    public $lastCall = null;
    public function update($user, $notrigger = 0)
    {
        $this->lastCall = ['user' => $user, 'notrigger' => $notrigger];
        return 1;
    }
}

class FakeNoArgUpdate
{
    public $id = 3;
    public $called = false;
    public function update()
    {
        $this->called = true;
        return 1;
    }
}

class FakeIdFirstDelete
{
    public $id = 9;
    public $lastCall = null;
    public function delete($id, $user = null)
    {
        $this->lastCall = ['id' => $id, 'user' => $user];
        return 1;
    }
}

class FakeUserFirstDelete
{
    public $id = 5;
    public $lastCall = null;
    public function delete($user, $notrigger = 0)
    {
        $this->lastCall = ['user' => $user, 'notrigger' => $notrigger];
        return 1;
    }
}

class FakeNoUserDelete
{
    public $id = 2;
    public $called = false;
    public function delete($notrigger = 0)
    {
        $this->called = true;
        return 1;
    }
}

class FakeCreate
{
    public $createdWith = null;
    public function create($user)
    {
        $this->createdWith = $user;
        return 11;
    }
}

/**
 * Unit tests for the reflection-based CRUD signature adapter.
 *
 * @covers \SmartAuth\Api\CrudInvoker
 */
class CrudInvokerTest extends TestCase
{
    /** @var object A stand-in "user". */
    private $user;

    protected function setUp(): void
    {
        $this->user = (object) ['id' => 1];
    }

    public function testUpdateIdFirstPassesObjectIdAndTrigger(): void
    {
        $o = new FakeIdFirstUpdate();
        $this->assertSame(1, CrudInvoker::update($o, $this->user));
        $this->assertSame(42, $o->lastCall['id']);
        $this->assertSame($this->user, $o->lastCall['user']);
        $this->assertSame(1, $o->lastCall['trigger']); // call_trigger enabled
    }

    public function testUpdateUserFirstPassesUserAndNotrigger(): void
    {
        $o = new FakeUserFirstUpdate();
        $this->assertSame(1, CrudInvoker::update($o, $this->user));
        $this->assertSame($this->user, $o->lastCall['user']);
        $this->assertSame(0, $o->lastCall['notrigger']); // notrigger=0 -> triggers on
    }

    public function testUpdateNoArgSignature(): void
    {
        $o = new FakeNoArgUpdate();
        $this->assertSame(1, CrudInvoker::update($o, $this->user));
        $this->assertTrue($o->called);
    }

    public function testDeleteIdFirstPassesObjectId(): void
    {
        $o = new FakeIdFirstDelete();
        $this->assertSame(1, CrudInvoker::delete($o, $this->user));
        $this->assertSame(9, $o->lastCall['id']);
        $this->assertSame($this->user, $o->lastCall['user']);
    }

    public function testDeleteUserFirstPassesUser(): void
    {
        $o = new FakeUserFirstDelete();
        $this->assertSame(1, CrudInvoker::delete($o, $this->user));
        $this->assertSame($this->user, $o->lastCall['user']);
    }

    public function testDeleteNoUserSignature(): void
    {
        $o = new FakeNoUserDelete();
        $this->assertSame(1, CrudInvoker::delete($o, $this->user));
        $this->assertTrue($o->called);
    }

    public function testCreatePassesUserAndReturnsNewId(): void
    {
        $o = new FakeCreate();
        $this->assertSame(11, CrudInvoker::create($o, $this->user));
        $this->assertSame($this->user, $o->createdWith);
    }
}
