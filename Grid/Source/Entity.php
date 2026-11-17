<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Abhoryo <abhoryo@free.fr>
 * (c) Stanislav Turza
 * (c) Patryk Grudniewski <patgrudniewski@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace APY\DataGridBundle\Grid\Source;

use APY\DataGridBundle\Grid\Column\Column;
use APY\DataGridBundle\Grid\Column\DateTimeColumn;
use APY\DataGridBundle\Grid\Column\JoinColumn;
use APY\DataGridBundle\Grid\Row;
use APY\DataGridBundle\Grid\Rows;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Tools\Pagination\CountWalker;
use Doctrine\ORM\Tools\Pagination\Paginator;

class Entity extends Source
{
    const DOT_DQL_ALIAS_PH = '__dot__';
    const COLON_DQL_ALIAS_PH = '__col__';

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $manager;

    /**
     * @var \Doctrine\ORM\QueryBuilder
     */
    protected $query;

    /**
     * @var \Doctrine\ORM\QueryBuilder
     */
    protected $querySelectfromSource;

    /**
     * @var string e.g Vendor\Bundle\Entity\Page
     */
    protected $class;

    /**
     * @var string e.g Cms:Page
     */
    protected $entityName;

    /**
     * @var string e.g mydatabase
     */
    protected $managerName;

    /**
     * @var \APY\DataGridBundle\Grid\Mapping\Metadata\Metadata
     */
    protected $metadata;

    /**
     * @var \Doctrine\ORM\Mapping\ClassMetadata
     */
    protected $ormMetadata;

    /**
     * @var array
     */
    protected $joins;

    /**
     * @var string
     */
    protected $group;

    /**
     * @var string
     */
    protected $groupBy;

    /**
     * @var array
     */
    protected $hints;

    /**
     * The QueryBuilder that will be used to start generating query for the DataGrid
     * You can override this if the querybuilder is constructed in a business-specific way
     * by an external controller/service/repository and you wish to re-use it for the datagrid.
     * Typical use-case involves an external repository creating complex default restriction (i.e. multi-tenancy etc)
     * which then will be expanded on by the datagrid.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * The table alias that will be used in the query to fetch actual data.
     *
     * @var string
     */
    protected $tableAlias;

    /**
     * @var null
     */
    protected $prepareCountQueryCallback = null;

    protected $knownTotalCount = null;

    /**
     * Legacy way of accessing the default alias (before it became possible to change it)
     * Please use $entity->getTableAlias() now instead of $entity::TABLE_ALIAS.
     *
     * @deprecated
     */
    const TABLE_ALIAS = '_a';

    /**
     * @param string $entityName  e.g Cms:Page
     * @param string $managerName e.g. mydatabase
     */
    public function __construct($entityName, $group = 'default', $managerName = null)
    {
        $this->entityName = $entityName;
        $this->managerName = $managerName;
        $this->joins = [];
        $this->group = $group;
        $this->hints = [];
        $this->setTableAlias(self::TABLE_ALIAS);
    }

    public function initialise($container)
    {
        $doctrine = $container->get('doctrine');

        $this->manager = $doctrine->getManager($this->managerName);
        $this->ormMetadata = $this->manager->getClassMetadata($this->entityName);

        $this->class = $this->ormMetadata->getReflectionClass()->name;

        $mapping = $container->get('grid.mapping.manager');

        /* todo autoregister mapping drivers with tag */
        $mapping->addDriver($this, -1);
        $this->metadata = $mapping->getMetadata($this->class, $this->group);

        $this->groupBy = $this->metadata->getGroupBy();
    }

    /**
     * @param \APY\DataGridBundle\Grid\Column\Column $column
     *
     * @return string
     */
    protected function getTranslationFieldNameWithParents($column)
    {
        $name = $column->getField();

        if ($column->getIsManualField()) {
            return $column->getField();
        }

        if (strpos($name, '.') !== false) {
            $previousParent = '';

            $elements = explode('.', $name);
            while ($element = array_shift($elements)) {
                if (count($elements) > 0) {
                    $previousParent .= '_' . $element;
                }
            }
        } elseif (strpos($name, ':') !== false) {
            $previousParent = $this->getTableAlias();
        } else {
            return $this->getTableAlias().'.'.$name;
        }

        $matches = array();
        if ($column->hasDQLFunction($matches)) {
            return $previousParent.'.'.$matches['field'];
        }

        return $column->getField();
    }

    /**
     * @param \APY\DataGridBundle\Grid\Column\Column $column
     * @return string
     */
    protected function getFieldName($column, $withAlias = false)
    {
        $name = $column->getField();

        if ($column->getIsManualField()) {
            return $column->getField();
        }

        if (strpos($name, '.') !== false) {
            $previousParent = '';

            $elements = explode('.', $name);
            while ($element = array_shift($elements)) {
                if (count($elements) > 0) {
                    $parent = ($previousParent == '') ? $this->getTableAlias() : $previousParent;
                    $previousParent .= '_' . $element;
                    $this->joins[$previousParent] = ['field' => $parent . '.' . $element, 'type' => $column->getJoinType()];
                } else {
                    $name = $previousParent . '.' . $element;
                }
            }

            $alias = $this->fromColIdToAlias($column->getId());
        } elseif (strpos($name, ':') !== false) {
            $previousParent = $this->getTableAlias();
            $alias = $name;
        } else {
            return $this->getTableAlias() . '.' . $name;
        }

        // Aggregate dql functions
        $matches = [];
        if ($column->hasDQLFunction($matches)) {
            if (strtolower($matches['parameters']) == 'distinct') {
                $functionWithParameters = $matches['function'] . '(DISTINCT ' . $previousParent . '.' . $matches['field'] . ')';
            } else {
                $parameters = '';
                if ($matches['parameters'] !== '') {
                    $parameters = ', ' . (is_numeric($matches['parameters']) ? $matches['parameters'] : "'" . $matches['parameters'] . "'");
                }

                $functionWithParameters = $matches['function'] . '(' . $previousParent . '.' . $matches['field'] . $parameters . ')';
            }

            if ($withAlias) {
                // Group by the primary field of the previous entity
                $this->query->addGroupBy($previousParent);
                $this->querySelectfromSource->addGroupBy($previousParent);

                return "$functionWithParameters as $alias";
            }

            return $alias;
        }

        if ($withAlias) {
            return "$name as $alias";
        }

        return $name;
    }

    /**
     * @param string $colId
     *
     * @return string
     */
    private function fromColIdToAlias($colId)
    {
        return str_replace(['.', ':'], [self::DOT_DQL_ALIAS_PH, self::COLON_DQL_ALIAS_PH], $colId);
    }

    /**
     * @param string $fieldName
     *
     * @return string
     */
    protected function getGroupByFieldName($fieldName)
    {
        if (strpos($fieldName, '.') !== false) {
            $previousParent = '';

            $elements = explode('.', $fieldName);
            while ($element = array_shift($elements)) {
                if (count($elements) > 0) {
                    $previousParent .= '_' . $element;
                } else {
                    $name = $previousParent . '.' . $element;
                }
            }
        } else {
            if (($pos = strpos($fieldName, ':')) !== false) {
                $fieldName = substr($fieldName, 0, $pos);
            }

            return $this->getTableAlias() . '.' . $fieldName;
        }

        return $name;
    }

    /**
     * @param \APY\DataGridBundle\Grid\Columns $columns
     */
    public function getColumns($columns)
    {
        foreach ($this->metadata->getColumnsFromMapping($columns) as $column) {
            $columns->addColumn($column);
        }
    }

    protected function normalizeOperator($operator)
    {
        switch ($operator) {
            //case Column::OPERATOR_REGEXP:
            case Column::OPERATOR_LIKE:
            case Column::OPERATOR_LLIKE:
            case Column::OPERATOR_RLIKE:
            case Column::OPERATOR_NLIKE:
            case Column::OPERATOR_SLIKE:
            case Column::OPERATOR_LSLIKE:
            case Column::OPERATOR_RSLIKE:
            case Column::OPERATOR_NSLIKE:
                return 'like';
            default:
                return $operator;
        }
    }

    protected function normalizeValue($operator, $value)
    {
        switch ($operator) {
            //case Column::OPERATOR_REGEXP:
            case Column::OPERATOR_LIKE:
            case Column::OPERATOR_NLIKE:
            case Column::OPERATOR_SLIKE:
            case Column::OPERATOR_NSLIKE:
                return "%$value%";
            case Column::OPERATOR_LLIKE:
            case Column::OPERATOR_LSLIKE:
                return "%$value";
            case Column::OPERATOR_RLIKE:
            case Column::OPERATOR_RSLIKE:
                return "$value%";
            default:
                return $value;
        }
    }

    /**
     * Sets the initial QueryBuilder for this DataGrid.
     *
     * @param QueryBuilder $queryBuilder
     */
    public function initQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = clone $queryBuilder;

        //Try to guess the new root alias and apply it to our queries+
        //as the external querybuilder almost certainly is not used our default alias
        $externalTableAliases = $this->queryBuilder->getRootAliases();
        if (count($externalTableAliases)) {
            $this->setTableAlias($externalTableAliases[0]);
        }
    }

    /**
     * @return QueryBuilder
     */
    protected function getQueryBuilder()
    {
        //If a custom QB has been provided, use a copy of that one
        //Otherwise create our own basic one
        if ($this->queryBuilder instanceof QueryBuilder) {
            $qb = clone $this->queryBuilder;
        } else {
            $qb = $this->manager->createQueryBuilder($this->class);
            $qb->from($this->class, $this->getTableAlias());
        }

        return $qb;
    }

    /**
     * @param \APY\DataGridBundle\Grid\Column\Column[] $columns
     * @param int                                      $page             Page Number
     * @param int                                      $limit            Rows Per Page
     * @param int                                      $gridDataJunction Grid data junction
     *
     * @return \APY\DataGridBundle\Grid\Rows
     */
    public function execute($columns, $page = 0, $limit = 0, $maxResults = null, $gridDataJunction = Column::DATA_CONJUNCTION)
    {
        $this->knownTotalCount = null;
        $this->query = $this->getQueryBuilder();
        $this->querySelectfromSource = clone $this->query;

        $bindIndex = 123;
        $where = $gridDataJunction === Column::DATA_CONJUNCTION ? $this->query->expr()->andx() : $this->query->expr()->orx();

        $columnsById = [];
        foreach ($columns as $column) {
            $columnsById[$column->getId()] = $column;
        }

        foreach ($columns as $column) {
            // If a column is a manual field, ie a.col*b.col as myfield, it is added to select from user.
            if ($column->getIsManualField() === false) {
                $fieldName = $this->getFieldName($column, true);
                $this->query->addSelect($fieldName);
                $this->querySelectfromSource->addSelect($fieldName);
            }

            if ($column->isSorted()) {
                if ($column instanceof JoinColumn) {
                    $this->query->resetDQLPart('orderBy');
                    foreach ($column->getJoinColumns() as $columnName) {
                        $this->query->addOrderBy($this->getFieldName($columnsById[$columnName]), $column->getOrder());
                    }
                } else {
                    $this->query->orderBy($this->getFieldName($column), $column->getOrder());
                }
            }

            if ($column->isFiltered()) {
                // Some attributes of the column can be changed in this function
                $filters = $column->getFilters('entity');

                $isDisjunction = $column->getDataJunction() === Column::DATA_DISJUNCTION;

                $dqlMatches = [];
                $hasHavingClause = $column->hasDQLFunction($dqlMatches) || $column->getIsAggregate();
                if(isset($dqlMatches['function']) && $dqlMatches['function'] == 'translation_agg'){
                    $hasHavingClause = false;
                }

                $sub = $isDisjunction ? $this->query->expr()->orx() : ($hasHavingClause ? $this->query->expr()->andx() : $where);

                foreach ($filters as $filter) {
                    $operator = $this->normalizeOperator($filter->getOperator());

                    $columnForFilter = (!$column instanceof JoinColumn) ? $column : $columnsById[$filter->getColumnName()];

                    $fieldName = $this->getFieldName($columnForFilter, false);
                    $bindIndexPlaceholder = "?$bindIndex";

                    if( in_array($filter->getOperator(), array(Column::OPERATOR_LIKE,Column::OPERATOR_RLIKE,Column::OPERATOR_LLIKE,Column::OPERATOR_NLIKE,))){
                        if(isset($dqlMatches['function']) && $dqlMatches['function'] == 'translation_agg'){
                            $translationFieldName = $this->getTranslationFieldNameWithParents($columnForFilter);
                            $fieldName = "LOWER(".$translationFieldName.")";
                        }elseif(isset($dqlMatches['function']) && $dqlMatches['function'] == 'role_agg'){
                            $translationFieldName = $this->getTranslationFieldNameWithParents($columnForFilter);
                            $fieldName = "LOWER(".$translationFieldName.")";
                        }else{
                            $fieldName = "LOWER($fieldName)";
                        }
                        $bindIndexPlaceholder = "LOWER($bindIndexPlaceholder)";
                    }

                    $q = $this->query->expr()->$operator($fieldName, $bindIndexPlaceholder);

                    if ($filter->getOperator() == Column::OPERATOR_NLIKE || $filter->getOperator() == Column::OPERATOR_NSLIKE) {
                        $q = $this->query->expr()->not($q);
                    }

                    $sub->add($q);

                    if ($filter->getValue() !== null) {
                        $this->query->setParameter($bindIndex++, $this->normalizeValue($filter->getOperator(), $filter->getValue()));
                    }
                }

                if ($hasHavingClause) {
                    $this->query->andHaving($sub);
                } elseif ($isDisjunction) {
                    $where->add($sub);
                }
            }
        }

        if ($where->count() > 0) {
            //Using ->andWhere here to make sure we preserve any other where clauses present in the query builder
            //the other where clauses may have come from an external builder
            $this->query->andWhere($where);
        }

        foreach ($this->joins as $alias => $field) {
            if (null !== $field['type'] && strtolower($field['type']) === 'inner') {
                $join = 'join';
            } else {
                $join = 'leftJoin';
            }

            $this->query->$join($field['field'], $alias);
            $this->querySelectfromSource->$join($field['field'], $alias);
        }

        if ($page > 0) {
            $this->query->setFirstResult($page * $limit);
        }

        if ($limit > 0) {
            if ($maxResults !== null && ($maxResults - $page * $limit < $limit)) {
                $limit = $maxResults - $page * $limit;
            }

            $this->query->setMaxResults($limit);
        } elseif ($maxResults !== null) {
            $this->query->setMaxResults($maxResults);
        }

        if (!empty($this->groupBy)) {
            $this->query->resetDQLPart('groupBy');
            $this->querySelectfromSource->resetDQLPart('groupBy');

            foreach ($this->groupBy as $field) {
                $this->query->addGroupBy($this->getGroupByFieldName($field));
                $this->querySelectfromSource->addGroupBy($this->getGroupByFieldName($field));
            }
        }

        //call overridden prepareQuery or associated closure
        $this->prepareQuery($this->query);
        $hasJoin = $this->checkIfQueryHasFetchJoin($this->query);

        if (!$hasJoin && $this->query->getDQLPart('orderBy') && null !== $this->getSimpleCountQuery(clone $this->query)) {
            $rootAlias = $this->query->getRootAliases()[0];
            $metadata = $this->query->getEntityManager()->getClassMetadata($this->query->getRootEntities()[0]);
            $identifier = $rootAlias.'.'.$metadata->getIdentifierFieldNames()[0];
            $hasIdentifierOrder = false;
            foreach ($this->query->getDQLPart('orderBy') as $orderBy) {
                foreach ($orderBy->getParts() as $part) {
                    $hasIdentifierOrder = $hasIdentifierOrder || (bool) preg_match('/^'.preg_quote($identifier, '/').'(?:\s+(?:ASC|DESC))?$/iD', $part);
                }
            }
            if (!$hasIdentifierOrder) {
                // Equal sort values must keep the same page membership across execution plans.
                $this->query->addOrderBy($identifier, 'ASC');
            }
        }

        $pageQuery = clone $this->query;
        foreach ($columns as $column) {
            if (!$column instanceof DateTimeColumn || !$column->isFiltered() || $hasJoin || 0 >= $limit || null !== $maxResults
                || null !== $this->prepareCountQueryCallback || self::class !== (new \ReflectionMethod($this, 'prepareCountQuery'))->getDeclaringClass()->getName()) {
                continue;
            }
            $summaryQuery = clone $this->query;
            if (null === $this->getSimpleCountQuery($summaryQuery)) {
                break;
            }
            $rootAlias = $summaryQuery->getRootAliases()[0];
            $metadata = $summaryQuery->getEntityManager()->getClassMetadata($summaryQuery->getRootEntities()[0]);
            $identifier = $metadata->getIdentifierFieldNames()[0];
            if (!in_array($metadata->getTypeOfField($identifier), ['integer', 'smallint', 'bigint'], true)) {
                break;
            }
            $field = $rootAlias.'.'.$identifier;
            $summary = $summaryQuery->select('COUNT('.$field.') AS total', 'MIN('.$field.') AS firstId', 'MAX('.$field.') AS lastId')
                ->setFirstResult(0)->setMaxResults(null)->getQuery()->getScalarResult()[0] ?? [];
            if (isset($summary['total'])) {
                $this->knownTotalCount = (int) $summary['total'];
                $remaining = $this->knownTotalCount - $page * $limit;
                if (0 >= $remaining) {
                    return new Rows();
                }
                // These integer bounds describe this exact filtered set, including nonchronological IDs.
                if (preg_match('/^-?\d+$/D', (string) $summary['firstId']) && preg_match('/^-?\d+$/D', (string) $summary['lastId'])) {
                    $pageQuery->andWhere($field.' >= '.$summary['firstId'])->andWhere($field.' <= '.$summary['lastId']);
                }
                $pageQuery->setMaxResults(min($limit, $remaining));
            }
            break;
        }

        $query = $pageQuery->getQuery();
        foreach ($this->hints as $hintKey => $hintValue) {
            $query->setHint($hintKey, $hintValue);
        }
        $items = new Paginator($query, $hasJoin);
        $items->setUseOutputWalkers(false);

        $repository = $this->manager->getRepository($this->entityName);

        // Force the primary field to get the entity in the manipulatorRow
        $primaryColumnId = null;
        foreach ($columns as $column) {
            if ($column->isPrimary()) {
                $primaryColumnId = $column->getId();

                break;
            }
        }

        // hydrate result
        $result = new Rows();
        $rawRowCount = 0;

        foreach ($items as $item) {
            ++$rawRowCount;
            $row = new Row();

            foreach ($item as $key => $value) {
                $key = $this->fromAliasToColId($key);

                $row->setField($key, $value);
            }

            $row->setPrimaryField($primaryColumnId);

            //Setting the representative repository for entity retrieving
            $row->setRepository($repository);

            //call overridden prepareRow or associated closure
            if (($modifiedRow = $this->prepareRow($row)) !== null) {
                $result->addRow($modifiedRow);
            }
        }

        if (0 === (int) $page && null === $maxResults && !$hasJoin && (0 === (int) $limit || $rawRowCount < $limit)) {
            $this->knownTotalCount = $rawRowCount;
        }

        return $result;
    }

    /**
     * @param string $alias
     *
     * @return string
     */
    private function fromAliasToColId($alias)
    {
        return str_replace([self::DOT_DQL_ALIAS_PH, self::COLON_DQL_ALIAS_PH], ['.', ':'], $alias);
    }

    public function getTotalCount($maxResults = null)
    {
        // Doctrine Bug Workaround: http://www.doctrine-project.org/jira/browse/DDC-1927
        $countQueryBuilder = clone $this->query;

        $this->prepareCountQuery($countQueryBuilder);

        $countQuery = $this->getSimpleCountQuery($countQueryBuilder);
        if (null !== $countQuery) {
            if (null === $maxResults && null !== $this->knownTotalCount && null === $this->prepareCountQueryCallback
                && self::class === (new \ReflectionMethod($this, 'prepareCountQuery'))->getDeclaringClass()->getName()) {
                return $this->knownTotalCount;
            }
            $countQuery->setFirstResult(0)->setMaxResults($maxResults);

            return (int) array_sum($countQuery->getSingleColumnResult());
        }

        foreach ($countQueryBuilder->getRootAliases() as $alias) {
            $countQueryBuilder->addSelect($alias);
        }

        // From Doctrine\ORM\Tools\Pagination\Paginator::count()
        $countQuery = $countQueryBuilder->getQuery();

        // Add hints from main query, if developer wants to use additional hints (ex. gedmo translations):
        foreach ($this->hints as $hintName => $hintValue) {
            $countQuery->setHint($hintName, $hintValue);
        }

        if (!$countQuery->getHint(CountWalker::HINT_DISTINCT)) {
            $countQuery->setHint(CountWalker::HINT_DISTINCT, true);
        }

        if ($countQuery->getHint(Query::HINT_CUSTOM_OUTPUT_WALKER) === false) {
            $rsm = new ResultSetMapping();
            $rsm->addScalarResult('dctrn_count', 'count');
            // Oracle and DB2 return unquoted column aliases in uppercase.
            $rsm->addScalarResult('DCTRN_COUNT', 'count');

            $countQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, 'Doctrine\ORM\Tools\Pagination\CountOutputWalker');
            $countQuery->setResultSetMapping($rsm);
        } else {
            $hints = $countQuery->getHint(Query::HINT_CUSTOM_TREE_WALKERS);

            if ($hints === false) {
                $hints = [];
            }

            $hints[] = 'Doctrine\ORM\Tools\Pagination\CountWalker';
            $countQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, $hints);
        }
        $countQuery->setFirstResult(0)->setMaxResults($maxResults);

        try {
            $data = $countQuery->getScalarResult();
            $data = array_map('current', $data);
            $count = array_sum($data);
        } catch (NoResultException $e) {
            $count = 0;
        }

        return $count;
    }

    private function getSimpleCountQuery(QueryBuilder $queryBuilder): ?Query
    {
        $rootAliases = $queryBuilder->getRootAliases();
        if (1 !== count($rootAliases) || $queryBuilder->getDQLPart('groupBy') || $queryBuilder->getDQLPart('having') || $this->hints) {
            return null;
        }

        // Expressions may aggregate rows or bind parameters; preserve the existing walker for them.
        foreach ($queryBuilder->getDQLPart('select') as $select) {
            foreach ($select->getParts() as $part) {
                if (!preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?(?:\s+(?:AS\s+)?(?:HIDDEN\s+)?[a-z_][a-z0-9_]*)?$/iD', $part)) {
                    return null;
                }
            }
        }
        foreach ($queryBuilder->getDQLPart('orderBy') as $orderBy) {
            foreach ($orderBy->getParts() as $part) {
                if (!preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?(?:\s+(?:ASC|DESC))?$/iD', $part)) {
                    return null;
                }
            }
        }

        $manager = $queryBuilder->getEntityManager();
        $rootAlias = $rootAliases[0];
        $metadata = $manager->getClassMetadata($queryBuilder->getRootEntities()[0]);
        $identifiers = $metadata->getIdentifierFieldNames();
        if (1 !== count($identifiers) || !$metadata->hasField($identifiers[0])) {
            return null;
        }

        $metadataByAlias = [$rootAlias => $metadata];
        $joins = $queryBuilder->getDQLPart('join');
        $removableJoins = [];
        $requiredExpressions = (string) $queryBuilder->getDQLPart('where');
        foreach ($joins as $rootJoins) {
            foreach ($rootJoins as $join) {
                $path = explode('.', $join->getJoin());
                $parentMetadata = $metadataByAlias[$path[0]] ?? null;
                if (2 === count($path) && null !== $parentMetadata && $parentMetadata->hasAssociation($path[1])) {
                    $metadataByAlias[$join->getAlias()] = $manager->getClassMetadata($parentMetadata->getAssociationTargetClass($path[1]));
                    if (Join::LEFT_JOIN === $join->getJoinType() && null === $join->getCondition() && $parentMetadata->isSingleValuedAssociation($path[1])) {
                        $removableJoins[$join->getAlias()] = $join;
                        continue;
                    }
                }

                $requiredExpressions .= ' '.$join->getJoin().' '.$join->getCondition();
            }
        }

        // Keep every parent needed by a filter or a retained join; extra matches only retain a join.
        do {
            $changed = false;
            foreach ($removableJoins as $alias => $join) {
                if (preg_match('/(?<![a-z0-9_.:])'.preg_quote($alias, '/').'(?![a-z0-9_])/i', $requiredExpressions)) {
                    $requiredExpressions .= ' '.$join->getJoin();
                    unset($removableJoins[$alias]);
                    $changed = true;
                }
            }
        } while ($changed);

        $queryBuilder->resetDQLPart('join')->resetDQLPart('orderBy');
        foreach ($joins as $alias => $rootJoins) {
            foreach ($rootJoins as $join) {
                if (!isset($removableJoins[$join->getAlias()])) {
                    $queryBuilder->add('join', [$alias => $join], true);
                }
            }
        }

        $distinct = $this->checkIfQueryHasFetchJoin($queryBuilder) ? 'DISTINCT ' : '';

        return $queryBuilder->select('COUNT('.$distinct.$rootAlias.'.'.$identifiers[0].') AS total')->getQuery();
    }

    public function getFieldsMetadata($class, $group = 'default')
    {
        $result = [];
        foreach ($this->ormMetadata->getFieldNames() as $name) {
            $mapping = $this->ormMetadata->getFieldMapping($name);
            $values = ['title' => $name, 'source' => true];

            if (isset($mapping['fieldName'])) {
                $values['field'] = $mapping['fieldName'];
                $values['id'] = $mapping['fieldName'];
            }

            if (isset($mapping['id']) && $mapping['id'] == 'id') {
                $values['primary'] = true;
            }

            switch ($mapping['type']) {
                case 'string':
                case 'text':
                    $values['type'] = 'text';
                    break;
                case 'integer':
                case 'smallint':
                case 'bigint':
                case 'float':
                case 'decimal':
                    $values['type'] = 'number';
                    break;
                case 'boolean':
                    $values['type'] = 'boolean';
                    break;
                case 'date':
                case 'date_immutable':
                    $values['type'] = 'date';
                    break;
                case 'datetime':
                case 'datetime_immutable':
                    $values['type'] = 'datetime';
                    break;
                case 'time':
                case 'time_immutable':
                    $values['type'] = 'time';
                    break;
                case 'json':
                    $values['type'] = 'array';
                    break;
            }

            $result[$name] = $values;
        }

        return $result;
    }

    public function populateSelectFilters($columns, $loop = false)
    {
        /* @var $column Column */
        foreach ($columns as $column) {
            $selectFrom = $column->getSelectFrom();

            if ($column->getFilterType() === 'select' && ($selectFrom === 'source' || $selectFrom === 'query')) {

                // For negative operators, show all values
                if ($selectFrom === 'query') {
                    foreach ($column->getFilters('entity') as $filter) {
                        if (in_array($filter->getOperator(), [Column::OPERATOR_NEQ, Column::OPERATOR_NLIKE, Column::OPERATOR_NSLIKE])) {
                            $selectFrom = 'source';
                            break;
                        }
                    }
                }

                // Dynamic from query or not ?
                $query = ($selectFrom === 'source') ? clone $this->querySelectfromSource : clone $this->query;

                $query = $query->select($this->getFieldName($column, true))
                    ->distinct()
                    ->orderBy($this->getFieldName($column), 'asc')
                    ->setFirstResult(null)
                    ->setMaxResults(null)
                    ->getQuery();
                if ($selectFrom === 'query') {
                    foreach ($this->hints as $hintKey => $hintValue) {
                        $query->setHint($hintKey, $hintValue);
                    }
                }
                $result = $query->getResult();

                $values = [];
                foreach ($result as $row) {
                    $alias = $this->fromColIdToAlias($column->getId());

                    $value = $row[$alias];

                    switch ($column->getType()) {
                        case 'array':
                            foreach ((array) $value as $val) {
                                $values[$val] = $val;
                            }
                            break;
                        case 'simple_array':
                            if (is_string($value)) {
                                $value = explode(',', $value);
                            }
                            foreach ($value as $val) {
                                $values[$val] = $val;
                            }
                            break;
                        case 'number':
                            $values[$value] = $column->getDisplayedValue($value);
                            break;
                        case 'datetime':
                        case 'date':
                        case 'time':
                            $displayedValue = $column->getDisplayedValue($value);
                            $values[$displayedValue] = $displayedValue;
                            break;
                        default:
                            $values[$value] = $value;
                    }
                }

                // It avoids to have no result when the other columns are filtered
                if ($selectFrom === 'query' && empty($values) && $loop === false) {
                    $column->setSelectFrom('source');
                    $this->populateSelectFilters($columns, true);
                } else {
                    if ($column->getType() == 'array') {
                        natcasesort($values);
                    }

                    $values = $this->prepareColumnValues($column, $values);
                    $column->setValues($values);
                }
            }
        }
    }

    /**
     * @param QueryBuilder $countQueryBuilder
     */
    public function prepareCountQuery(QueryBuilder $countQueryBuilder)
    {
        if (is_callable($this->prepareCountQueryCallback)) {
            call_user_func($this->prepareCountQueryCallback, $countQueryBuilder);
        }
    }

    /**
     * @param callable $callback
     *
     * @return $this
     */
    public function manipulateCountQuery($callback = null)
    {
        $this->prepareCountQueryCallback = $callback;

        return $this;
    }

    public function delete(array $ids)
    {
        $repository = $this->getRepository();

        foreach ($ids as $id) {
            $object = $repository->find($id);

            if (!$object) {
                throw new \Exception(sprintf('No %s found for id %s', $this->entityName, $id));
            }

            $this->manager->remove($object);
        }

        $this->manager->flush();
    }

    public function getRepository()
    {
        return $this->manager->getRepository($this->entityName);
    }

    public function getHash()
    {
        return $this->entityName;
    }

    public function addHint($key, $value)
    {
        $this->hints[$key] = $value;
    }

    public function clearHints()
    {
        $this->hints = [];
    }

    /**
     *  Set groupby column.
     *
     *  @param string $groupBy GroupBy column
     */
    public function setGroupBy($groupBy)
    {
        $this->groupBy = $groupBy;
    }

    public function getEntityName()
    {
        return $this->entityName;
    }

    /**
     * @param string $tableAlias
     */
    public function setTableAlias($tableAlias)
    {
        $this->tableAlias = $tableAlias;
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return $this->tableAlias;
    }

    /**
     * @param QueryBuilder $qb
     * @return boolean
     */
    protected function checkIfQueryHasFetchJoin(QueryBuilder $qb)
    {
        $manager = $qb->getEntityManager();
        $metadataByAlias = [];
        foreach ($qb->getRootAliases() as $index => $alias) {
            $metadataByAlias[$alias] = $manager->getClassMetadata($qb->getRootEntities()[$index]);
        }

        foreach ($qb->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                $path = explode('.', $join->getJoin());
                $metadata = $metadataByAlias[$path[0]] ?? null;
                // Unknown joins may multiply root rows, so retain Doctrine's two-query pagination.
                if (2 !== count($path) || null === $metadata || !$metadata->hasAssociation($path[1]) || $metadata->isCollectionValuedAssociation($path[1])) {
                    return true;
                }
                $metadataByAlias[$join->getAlias()] = $manager->getClassMetadata($metadata->getAssociationTargetClass($path[1]));
            }
        }

        return false;
    }
}
