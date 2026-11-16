# PHP attributes

Grid mapping uses native PHP attributes and requires PHP 8.1 or later.
The Doctrine annotation reader is no longer used. Existing PHP grid builders
continue to work, and properties are still discovered without any APY attribute,
including inherited properties. Doctrine metadata supplies their storage types.

```php
use APY\DataGridBundle\Grid\Mapping as GRID;

#[GRID\Source(columns: ['id', 'name', 'owner.name', 'summary'])]
#[GRID\Column(id: 'summary', type: 'text', title: 'Summary')]
class Product
{
    private int $id;

    #[GRID\Column(title: 'Name', filter: 'select', selectFrom: 'values', values: ['a' => 'A'])]
    private string $name;

    #[GRID\Column(field: 'owner.name', title: 'Owner')]
    private $owner;
}
```

`Column` can be repeated on a property or a class, and `Source` can be repeated
on a class for different groups. All mapping options are accepted as named
arguments. Passing an array of options remains supported for PHP configuration.
`Source::columns` accepts either an array or the existing comma-separated string.

```php
#[GRID\Source(columns: ['id', 'name'])]
#[GRID\Source(columns: ['name'], groups: ['admin'], groupBy: ['name'])]
class GroupedProduct
{
    private int $id;

    #[GRID\Column(title: 'Name', groups: ['default'])]
    #[GRID\Column(title: 'Internal name', groups: ['admin'])]
    private string $name;
}
```

## Migration

Convert `@GRID\Source(...)` and `@GRID\Column(...)` to `#[GRID\Source(...)]`
and `#[GRID\Column(...)]`, using named arguments (`title: 'Name'`) and PHP arrays.
An `id` is required for class-level extra columns and must not be set on a
property attribute. Other libraries' attributes are not instantiated by APY.

The previous annotation examples in the historical documentation are no longer
supported. The old, unregistered JMS annotation translation extractor was removed.
Cell title translation during rendering is unchanged.

Remove application overrides referencing `grid.metadata.driver.annotation`;
the replacement service is `grid.metadata.driver.attribute`. Remove readers and
caches created solely for APY. Check `composer why doctrine/annotations` before
removing the package, as another dependency may still need it.
