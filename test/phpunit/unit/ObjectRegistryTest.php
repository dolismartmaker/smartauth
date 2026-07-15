<?php

namespace SmartAuth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartAuth\Api\ObjectRegistry;

/**
 * Unit tests for the shared ObjectRegistry (single source of truth for the
 * core-object CRUD facade + sync engine).
 *
 * @covers \SmartAuth\Api\ObjectRegistry
 */
class ObjectRegistryTest extends TestCase
{
    /** @var string[] The Vague 1 types the registry ships built-in. */
    private const VAGUE1 = ['thirdparty', 'contact', 'product', 'category'];

    public function testBuiltinsExposesTheFourVague1Types(): void
    {
        $builtins = ObjectRegistry::builtins();
        foreach (self::VAGUE1 as $type) {
            $this->assertArrayHasKey($type, $builtins, "built-in type $type missing");
        }
    }

    public function testEveryBuiltinCarriesTheKeysTheFacadeNeeds(): void
    {
        $required = ['class', 'file', 'table', 'element', 'module', 'rights', 'mapper', 'alias', 'default_sort'];
        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $cfg, "type $type is missing config key '$key'");
            }
            $this->assertNotSame('', (string) $cfg['class']);
            $this->assertNotSame('', (string) $cfg['table']);
            $this->assertNotSame('', (string) $cfg['alias']);
        }
    }

    public function testRightsMapCoversEveryCrudAction(): void
    {
        foreach (ObjectRegistry::builtins() as $type => $cfg) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $this->assertArrayHasKey($action, $cfg['rights'], "type $type lacks $action right");
                $this->assertIsArray($cfg['rights'][$action]);
                $this->assertNotEmpty($cfg['rights'][$action]);
            }
        }
    }

    public function testMapperClassesPointToTheDmNamespace(): void
    {
        $expected = [
            'thirdparty' => '\\SmartAuth\\DolibarrMapping\\dmThirdparty',
            'contact'    => '\\SmartAuth\\DolibarrMapping\\dmContact',
            'product'    => '\\SmartAuth\\DolibarrMapping\\dmProduct',
            'category'   => '\\SmartAuth\\DolibarrMapping\\dmCategory',
        ];
        $builtins = ObjectRegistry::builtins();
        foreach ($expected as $type => $mapper) {
            $this->assertSame($mapper, $builtins[$type]['mapper']);
        }
    }

    public function testGetReturnsConfigForKnownTypeStampedWithObjectType(): void
    {
        $cfg = ObjectRegistry::get('thirdparty');
        $this->assertIsArray($cfg);
        $this->assertSame('thirdparty', $cfg['object_type']);
        $this->assertSame('Societe', $cfg['class']);
    }

    public function testGetReturnsNullForUnknownType(): void
    {
        $this->assertNull(ObjectRegistry::get('does_not_exist'));
        $this->assertFalse(ObjectRegistry::has('does_not_exist'));
        $this->assertTrue(ObjectRegistry::has('product'));
    }

    public function testTypesListsEveryBuiltinType(): void
    {
        $types = ObjectRegistry::types();
        foreach (self::VAGUE1 as $type) {
            $this->assertContains($type, $types);
        }
    }

    public function testResolveWithHooksStampsObjectTypeOnEachConfig(): void
    {
        $all = ObjectRegistry::resolveWithHooks(null);
        foreach ($all as $type => $cfg) {
            $this->assertSame($type, $cfg['object_type']);
        }
    }
}
