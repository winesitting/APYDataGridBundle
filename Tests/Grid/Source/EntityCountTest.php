<?php

namespace APY\DataGridBundle\Tests\Grid\Source;

use APY\DataGridBundle\Grid\Source\Entity;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class EntityCountTest extends TestCase
{
    private $manager;
    private $logger;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The SQLite PDO driver is required.');
        }

        $this->logger = new class extends AbstractLogger {
            public array $queries = [];

            public function log($level, $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }
        };
        $connectionConfig = new Configuration();
        $connectionConfig->setMiddlewares([new Middleware($this->logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $connectionConfig);
        $config = ORMSetup::createConfiguration(true);
        $config->setMetadataDriverImpl(new StaticPHPDriver([]));
        if (method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
        $this->manager = new EntityManager($connection, $config);
        $classes = [CountRecord::class, CountOwner::class, CountRegion::class, CountChild::class, CompositeCountRecord::class];
        (new SchemaTool($this->manager))->createSchema(array_map([$this->manager, 'getClassMetadata'], $classes));

        $region = new CountRegion();
        $region->id = 1;
        $region->name = 'north';
        $owner = new CountOwner();
        $owner->id = 1;
        $owner->name = 'Alice';
        $owner->region = $region;
        $this->manager->persist($region);
        $this->manager->persist($owner);
        foreach ([1, 2, 3, 4] as $id) {
            $record = new CountRecord();
            $record->id = $id;
            $record->category = $id % 2;
            $record->owner = $id <= 2 ? $owner : null;
            $this->manager->persist($record);
            if (1 === $id) {
                foreach ([1, 2] as $childId) {
                    $child = new CountChild();
                    $child->id = $childId;
                    $child->name = 'red';
                    $child->record = $record;
                    $this->manager->persist($child);
                }
            }
        }
        foreach ([[1, 1], [1, 2], [2, 1]] as [$first, $second]) {
            $record = new CompositeCountRecord();
            $record->first = $first;
            $record->second = $second;
            $this->manager->persist($record);
        }
        $this->manager->flush();
        $this->logger->queries = [];
    }

    protected function tearDown(): void
    {
        if (null !== $this->manager) {
            $connection = $this->manager->getConnection();
            $this->manager->close();
            $connection->close();
        }
    }

    public function testCountSkipsDisplayJoinsAndPreservesPaginationQuery(): void
    {
        $query = $this->createQuery()->orderBy('owner.name', 'ASC')->setFirstResult(2)->setMaxResults(1);
        $dql = $query->getDQL();
        $source = $this->createSource($query);

        $this->assertSame(4, $source->getTotalCount());
        $sql = end($this->logger->queries);
        $this->assertStringNotContainsString('JOIN', $sql);
        $this->assertStringNotContainsString('ORDER BY', $sql);
        $this->assertStringNotContainsString('FROM (', $sql);
        $this->assertSame($dql, $query->getDQL());
        $this->assertSame(2, $query->getFirstResult());
        $this->assertSame(1, $query->getMaxResults());
        $this->assertSame(4, $source->getTotalCount(1));
        $this->assertSame(0, $source->getTotalCount(0));
    }

    /** @dataProvider provideRelationFilters */
    public function testCountRetainsRelationsUsedByFilters($where, $value, $expectedCount, $expectedJoins): void
    {
        $query = $this->createQuery()->where($where)->setParameter('value', $value);
        $this->assertSame($expectedCount, $this->createSource($query)->getTotalCount());
        $this->assertSame($expectedJoins, substr_count(end($this->logger->queries), 'JOIN'));
    }

    public function provideRelationFilters(): array
    {
        return [
            'direct relation' => ['owner.name = :value', 'Alice', 2, 1],
            'nested relation' => ['region.name = :value', 'north', 2, 2],
            'entity alias' => ['owner = :value', 1, 2, 1],
            'empty result' => ['owner.name = :value', 'missing', 0, 1],
            'root field' => ['record.category = :value', 1, 2, 0],
            'correlated subquery' => ['record.id IN (SELECT matching.id FROM '.CountRecord::class.' matching WHERE matching.owner = owner AND matching.category = :value)', 1, 1, 1],
        ];
    }

    public function testInnerJoinRetainsItsLeftJoinedParent(): void
    {
        $query = $this->createQuery()->innerJoin('owner.region', 'requiredRegion');
        $this->assertSame(2, $this->createSource($query)->getTotalCount());
        $this->assertSame(2, substr_count(end($this->logger->queries), 'JOIN'));
    }

    public function testCollectionJoinDoesNotDuplicateTheCount(): void
    {
        $query = $this->createQuery()->leftJoin('record.children', 'child');
        $source = $this->createSource($query);
        $this->assertSame(4, $source->getTotalCount());
        $this->assertSame(1, substr_count(end($this->logger->queries), 'JOIN'));
        $query->where('child.name = :name')->setParameter('name', 'red');
        $this->assertSame(1, $source->getTotalCount());
    }

    public function testConditionalJoinKeepsItsBoundParameter(): void
    {
        $query = $this->createQuery()
            ->leftJoin('record.owner', 'conditionalOwner', 'WITH', 'conditionalOwner.name = :name')
            ->setParameter('name', 'Alice');
        $this->assertSame(4, $this->createSource($query)->getTotalCount());
        $this->assertSame(1, substr_count(end($this->logger->queries), 'JOIN'));
    }

    public function testCountCallbackRunsOnTheClone(): void
    {
        $query = $this->createQuery();
        $dql = $query->getDQL();
        $source = $this->createSource($query);
        $source->manipulateCountQuery(static function (QueryBuilder $countQuery): void {
            $countQuery->where('record.category = :category')->setParameter('category', 1);
        });
        $this->assertSame(2, $source->getTotalCount());
        $this->assertSame($dql, $query->getDQL());
        $this->assertCount(0, $query->getParameters());
    }

    public function testConditionalJoinRetainsReferencedRelations(): void
    {
        $query = $this->createQuery()
            ->leftJoin('record.owner', 'conditionalOwner', 'WITH', 'conditionalOwner.name = owner.name');
        $this->assertSame(4, $this->createSource($query)->getTotalCount());
        $this->assertSame(2, substr_count(end($this->logger->queries), 'JOIN'));
    }

    public function testSelectedExpressionKeepsItsParameterAndTheExistingCountWalker(): void
    {
        $query = $this->createQuery()
            ->addSelect('CASE WHEN record.category = :category THEN 0 ELSE 1 END AS marker')
            ->setParameter('category', 1);
        $this->assertSame(4, $this->createSource($query)->getTotalCount());
        $this->assertStringContainsString('FROM (', end($this->logger->queries));
    }

    public function testGroupedQueryKeepsTheExistingCountWalker(): void
    {
        $query = $this->createQuery()->groupBy('record.category')->having('COUNT(record.id) > :minimum')->setParameter('minimum', 1);
        $this->assertSame(2, $this->createSource($query)->getTotalCount());
        $this->assertStringContainsString('FROM (', end($this->logger->queries));
    }

    public function testCustomHintsKeepTheExistingCountWalker(): void
    {
        $source = $this->createSource($this->createQuery());
        $source->addHint('custom.test', true);
        $this->assertSame(4, $source->getTotalCount());
        $this->assertStringContainsString('FROM (', end($this->logger->queries));
    }

    public function testCompositeIdentifierKeepsTheExistingCountWalker(): void
    {
        $query = $this->manager->createQueryBuilder()->select('record')->from(CompositeCountRecord::class, 'record');
        $this->assertSame(3, $this->createSource($query)->getTotalCount());
        $this->assertStringContainsString('FROM (', end($this->logger->queries));
    }

    public function testOrderExpressionKeepsItsParameterAndTheExistingCountWalker(): void
    {
        $query = $this->createQuery()->orderBy('CASE WHEN record.category = :category THEN 0 ELSE 1 END')->setParameter('category', 1);
        $this->assertSame(4, $this->createSource($query)->getTotalCount());
        $this->assertStringContainsString('FROM (', end($this->logger->queries));
    }

    private function createQuery(): QueryBuilder
    {
        return $this->manager->createQueryBuilder()
            ->select('record.id AS record_id', 'owner.name AS owner_name', 'region.name AS region_name')
            ->from(CountRecord::class, 'record')
            ->leftJoin('record.owner', 'owner')
            ->leftJoin('owner.region', 'region');
    }

    private function createSource(QueryBuilder $query): Entity
    {
        return new class($query) extends Entity {
            public function __construct(QueryBuilder $query)
            {
                parent::__construct($query->getRootEntities()[0]);
                $this->query = $query;
            }
        };
    }
}

class CountRecord
{
    public $id;
    public $category;
    public $owner;
    public $children;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'category', 'type' => 'integer']);
        $metadata->mapManyToOne(['fieldName' => 'owner', 'targetEntity' => CountOwner::class]);
        $metadata->mapOneToMany(['fieldName' => 'children', 'targetEntity' => CountChild::class, 'mappedBy' => 'record']);
    }
}

class CountOwner
{
    public $id;
    public $name;
    public $region;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'name', 'type' => 'string']);
        $metadata->mapManyToOne(['fieldName' => 'region', 'targetEntity' => CountRegion::class]);
    }
}

class CountRegion
{
    public $id;
    public $name;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'name', 'type' => 'string']);
    }
}

class CountChild
{
    public $id;
    public $name;
    public $record;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'name', 'type' => 'string']);
        $metadata->mapManyToOne(['fieldName' => 'record', 'targetEntity' => CountRecord::class, 'inversedBy' => 'children']);
    }
}

class CompositeCountRecord
{
    public $first;
    public $second;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(['fieldName' => 'first', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'second', 'type' => 'integer', 'id' => true]);
    }
}
