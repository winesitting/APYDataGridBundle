<?php

namespace APY\DataGridBundle\Tests\Grid\Source;

use APY\DataGridBundle\Grid\Source\Entity;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;
use PHPUnit\Framework\TestCase;

class EntityTest extends TestCase
{
    /**
     * @dataProvider provideResultCasing
     */
    public function testCountsWithLowerAndUppercaseAliases($casing)
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The SQLite PDO driver is required.');
        }

        $config = ORMSetup::createConfiguration(true);
        $config->setMetadataDriverImpl(new StaticPHPDriver([]));
        $manager = EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $connection = $manager->getConnection();

        try {
            (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(CountedRecord::class)]);
            foreach ([1, 2, 3] as $id) {
                $record = new CountedRecord();
                $record->id = $id;
                $record->category = $id % 2;
                $manager->persist($record);
            }
            $manager->flush();
            $connection->getNativeConnection()->setAttribute(\PDO::ATTR_CASE, $casing);

            $query = $manager->createQueryBuilder()->select('record')->from(CountedRecord::class, 'record');
            $source = new class($query) extends Entity {
                public function __construct($query)
                {
                    parent::__construct(CountedRecord::class);
                    $this->query = $query;
                }
            };

            $this->assertSame(3, (int) $source->getTotalCount());
            $query->groupBy('record.category');
            $this->assertSame(2, (int) $source->getTotalCount());
            $query->resetDQLPart('groupBy')->where('record.id < 0');
            $this->assertSame(0, (int) $source->getTotalCount());
        } finally {
            $manager->close();
            $connection->close();
        }
    }

    public function provideResultCasing(): array
    {
        return [
            'lowercase aliases' => [\PDO::CASE_LOWER],
            'uppercase aliases' => [\PDO::CASE_UPPER],
        ];
    }
}

class CountedRecord
{
    public $id;
    public $category;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'category', 'type' => 'integer']);
    }
}
