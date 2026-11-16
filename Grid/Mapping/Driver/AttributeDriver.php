<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Abhoryo <abhoryo@free.fr>
 * (c) Stanislav Turza
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace APY\DataGridBundle\Grid\Mapping\Driver;

use APY\DataGridBundle\Grid\Mapping\Column as Column;
use APY\DataGridBundle\Grid\Mapping\Source as Source;

class AttributeDriver implements DriverInterface
{
    protected $columns;
    protected $filterable;
    protected $sortable;
    protected $fields;
    protected $loaded;
    protected $groupBy;

    public function __construct()
    {
        $this->columns = $this->fields = $this->loaded = $this->groupBy = $this->filterable = $this->sortable = [];
    }

    public function getClassColumns($class, $group = 'default')
    {
        $this->loadMetadata($class, $group);

        return $this->columns[$class][$group];
    }

    public function getFieldsMetadata($class, $group = 'default')
    {
        $this->loadMetadata($class, $group);

        return $this->fields[$class][$group];
    }

    public function getGroupBy($class, $group = 'default')
    {
        $this->loadMetadata($class, $group);

        return isset($this->groupBy[$class][$group]) ? $this->groupBy[$class][$group] : [];
    }

    protected function loadMetadata($className, $group = 'default')
    {
        if (isset($this->loaded[$className][$group])) {
            return;
        }

        $reflectionCollection = [];
        $this->fields[$className][$group] = [];

        $reflectionCollection[] = $reflection = new \ReflectionClass($className);
        while (false !== $reflection = $reflection->getParentClass()) {
            $reflectionCollection[] = $reflection;
        }

        while (!empty($reflectionCollection)) {
            $reflection = array_pop($reflectionCollection);

            foreach ($reflection->getAttributes(Source::class) as $attribute) {
                $this->getMetadataFromClass($className, $attribute->newInstance(), $group);
            }

            foreach ($reflection->getAttributes(Column::class) as $attribute) {
                $this->getMetadataFromClass($className, $attribute->newInstance(), $group);
            }

            foreach ($reflection->getProperties() as $property) {
                $this->fields[$className][$group][$property->getName()] = [];

                foreach ($property->getAttributes(Column::class) as $attribute) {
                    $this->getMetadataFromClassProperty($className, $attribute->newInstance(), $property->getName(), $group);
                }
            }
        }

        if (empty($this->columns[$className][$group])) {
            $this->columns[$className][$group] = array_keys($this->fields[$className][$group]);
        } else {
            foreach ($this->columns[$className][$group] as $columnId) {
                // Ignore mapped fields
                if (strpos($columnId, '.') === false) {
                    if (!isset($this->fields[$className][$group][$columnId]['filterable'])) {
                        $this->fields[$className][$group][$columnId]['filterable'] = $this->filterable[$className][$group] ?? true;
                    }
                    if (!isset($this->fields[$className][$group][$columnId]['sortable'])) {
                        $this->fields[$className][$group][$columnId]['sortable'] = $this->sortable[$className][$group] ?? true;
                    }
                }
            }
        }

        $this->loaded[$className][$group] = true;
    }

    protected function getMetadataFromClassProperty($className, $class, $name = null, $group = 'default')
    {
        if ($class instanceof Column) {
            $metadata = $class->getMetadata();

            if (isset($metadata['id']) && $name !== null) {
                throw new \Exception(sprintf('Parameter `id` can\'t be used in attributes for property `%s`, please remove it from class %s', $name, $className));
            }

            if ($name === null) { // Class Column attribute
                if (isset($metadata['id'])) {
                    $metadata['source'] = false;
                    $this->fields[$className][$group][$metadata['id']] = [];
                } else {
                    throw new \Exception(sprintf('Missing parameter `id` in attributes for extra column of class %s', $className));
                }
            } else { // Property Column attribute
                // Relationship handle
                if (isset($metadata['field']) && (strpos($metadata['field'], '.') !== false || strpos($metadata['field'], ':') !== false)) {
                    $metadata['id'] = $metadata['field'];

                    // Title is not set by default like properties of the entity (see getFieldsMetadata method of a source)
                    if (!isset($metadata['title'])) {
                        $metadata['title'] = $metadata['field'];
                    }
                } else {
                    $metadata['id'] = $name;
                }
            }

            // Keep the first matching attribute for this group.
            if (isset($metadata['groups']) && !in_array($group, (array) $metadata['groups'])
                || isset($this->fields[$className][$group][$metadata['id']]['groups'])) {
                return;
            }

            if (!isset($metadata['filterable'])) {
                $metadata['filterable'] = isset($this->filterable[$className][$group]) ? $this->filterable[$className][$group] : true;
            }

            if (!isset($metadata['sortable'])) {
                $metadata['sortable'] = isset($this->sortable[$className][$group]) ? $this->sortable[$className][$group] : true;
            }

            if (!isset($metadata['title'])) {
                $metadata['title'] = $metadata['id'];
            }

            if (isset($metadata['field'])) {
                $metadata['source'] = true;
            }

            $this->fields[$className][$group][$metadata['id']] = $metadata;
        }
    }

    protected function getMetadataFromClass($className, $class, $group)
    {
        if ($class instanceof Source) {
            foreach ($class->getGroups() as $sourceGroup) {
                $this->columns[$className][$sourceGroup] = $class->getColumns();
                $this->filterable[$className][$sourceGroup] = $class->isFilterable();
                $this->sortable[$className][$sourceGroup] = $class->isSortable();
                $this->groupBy[$className][$sourceGroup] = $class->getGroupBy();
            }
        } elseif ($class instanceof Column) {
            $this->getMetadataFromClassProperty($className, $class, null, $group);
        }
    }
}
