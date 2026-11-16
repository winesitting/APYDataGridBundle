<?php

namespace APY\DataGridBundle\Tests\Grid\Mapping\Driver;

use APY\DataGridBundle\Grid\Mapping\Column;
use APY\DataGridBundle\Grid\Mapping\Driver\AttributeDriver;
use APY\DataGridBundle\Grid\Mapping\Source;
use PHPUnit\Framework\TestCase;

class AttributeDriverTest extends TestCase
{
    public function testDiscoversPropertiesWithoutAttributes()
    {
        $driver = new AttributeDriver();

        $this->assertSame(['id', 'name'], $driver->getClassColumns(PlainRecord::class));
        $this->assertSame(['id' => [], 'name' => []], $driver->getFieldsMetadata(PlainRecord::class));
        $this->assertSame([], $driver->getGroupBy(PlainRecord::class));
        $this->assertSame([], $driver->getClassColumns(\stdClass::class));
    }

    public function testPreservesInheritedMetadataAndDiscoversChildProperties()
    {
        $driver = new AttributeDriver();
        $fields = $driver->getFieldsMetadata(ChildRecord::class);

        $this->assertSame(['id', 'name'], $driver->getClassColumns(ChildRecord::class));
        $this->assertSame('Identifier', $fields['id']['title']);
        $this->assertSame([], $fields['name']);
    }

    public function testReadsClassOptionsExtraColumnsAndRelatedFields()
    {
        $driver = new AttributeDriver();
        $fields = $driver->getFieldsMetadata(ConfiguredRecord::class);

        $this->assertSame(['id', 'owner.name', 'extra'], $driver->getClassColumns(ConfiguredRecord::class));
        $this->assertFalse($fields['id']['filterable']);
        $this->assertFalse($fields['id']['sortable']);
        $this->assertFalse($fields['extra']['source']);
        $this->assertSame('Extra', $fields['extra']['title']);
        $this->assertSame('owner.name', $fields['owner.name']['id']);
        $this->assertTrue($fields['owner.name']['source']);
    }

    public function testGroupsAreIndependentAndGroupByCanBeReadFirst()
    {
        $driver = new AttributeDriver();

        $this->assertSame(['name'], $driver->getGroupBy(GroupedRecord::class, 'admin'));
        $this->assertSame(['name'], $driver->getClassColumns(GroupedRecord::class, 'admin'));
        $this->assertSame('Admin name', $driver->getFieldsMetadata(GroupedRecord::class, 'admin')['name']['title']);
        $this->assertSame('Public name', $driver->getFieldsMetadata(GroupedRecord::class)['name']['title']);
        $this->assertSame(['id', 'name'], $driver->getClassColumns(GroupedRecord::class));
        $this->assertSame([], $driver->getGroupBy(GroupedRecord::class));
    }

    public function testRejectsIdOnPropertyAttributes()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Parameter `id`');

        (new AttributeDriver())->getFieldsMetadata(InvalidPropertyRecord::class);
    }

    public function testRejectsClassColumnsWithoutId()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing parameter `id`');

        (new AttributeDriver())->getFieldsMetadata(InvalidExtraColumnRecord::class);
    }
}

#[UnrelatedAttribute]
class PlainRecord
{
    private $id;
    protected $name;
}

class ParentRecord
{
    #[Column(title: 'Identifier')]
    protected $id;
}

class ChildRecord extends ParentRecord
{
    private $name;
}

#[Source(columns: ['id', 'owner.name', 'extra'], filterable: false, sortable: false)]
#[Column(id: 'extra', title: 'Extra', type: 'text')]
class ConfiguredRecord extends ParentRecord
{
    #[Column(field: 'owner.name')]
    private $owner;
}

#[Source(columns: ['id', 'name'])]
#[Source(columns: ['name'], groups: ['admin'], groupBy: ['name'])]
class GroupedRecord
{
    private $id;

    #[Column(title: 'Public name', groups: ['default'])]
    #[Column(title: 'Admin name', groups: ['admin'])]
    private $name;
}

class InvalidPropertyRecord
{
    #[Column(id: 'invalid')]
    private $name;
}

#[Column(title: 'Missing id')]
class InvalidExtraColumnRecord
{
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class UnrelatedAttribute
{
    public function __construct()
    {
        throw new \LogicException('Unrelated attributes must not be instantiated.');
    }
}
