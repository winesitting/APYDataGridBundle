<?php

namespace APY\DataGridBundle\Tests\Twig;

use APY\DataGridBundle\Grid\Column\Column;
use APY\DataGridBundle\Grid\Column\TextColumn;
use APY\DataGridBundle\Grid\Grid;
use APY\DataGridBundle\Twig\DataGridExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Class DataGridExtensionTest.
 *
 *
 * @author Quentin FERRER
 */
class DataGridExtensionTest extends TestCase
{
    private \APY\DataGridBundle\Twig\DataGridExtension $extension;

    public function setUp(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $this->extension = new DataGridExtension($router, '');
    }

    public function testGetGridUrl()
    {
        $baseUrl = 'http://localhost';
        $gridHash = 'my_grid';

        // Creates grid
        $grid = $this->createMock(Grid::class);
        $grid->expects($this->any())->method('getRouteUrl')->willReturn($baseUrl);
        $grid->expects($this->any())->method('getHash')->willReturn($gridHash);

        $prefix = $baseUrl . '?' . $gridHash;

        // Creates column
        $column = $this->createMock(Column::class);

        // Limit
        $this->assertEquals($prefix . '[_limit]=', $this->extension->getGridUrl('limit', $grid, $column));

        // Reset
        $this->assertEquals($prefix . '[_reset]=', $this->extension->getGridUrl('reset', $grid, $column));

        // Page
        $this->assertEquals($prefix . '[_page]=2', $this->extension->getGridUrl('page', $grid, 2));

        // Export
        $this->assertEquals($prefix . '[__export_id]=pdf', $this->extension->getGridUrl('export', $grid, 'pdf'));

        // Default order
        $column->expects($this->any())->method('getId')->willReturn('foo');
        $this->assertEquals($prefix . '[_order]=foo|asc', $this->extension->getGridUrl('order', $grid, $column));

        // Order
        $column->expects($this->any())->method('isSorted')->willReturn(true);
        $column->expects($this->any())->method('getOrder')->willReturn('asc');
        $this->assertEquals($prefix . '[_order]=foo|desc', $this->extension->getGridUrl('order', $grid, $column));

        // Unknown section
        $this->assertNull($this->extension->getGridUrl('', $grid, $column));
    }

    public function testRendersCellsAndPagerWithoutDeprecatedFilters()
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/Resources/views', 'APYDataGrid');
        $twig = new Environment($loader, ['autoescape' => 'html']);
        $twig->addExtension($this->extension);
        $twig->addExtension(new RoutingExtension($this->createMock(RouterInterface::class)));
        $twig->addFilter(new TwigFilter('trans', static fn($message) => $message));
        $deprecations = [];
        set_error_handler(static function ($severity, $message) use (&$deprecations) {
            if ($severity === E_USER_DEPRECATED) {
                $deprecations[] = $message;

                return true;
            }

            return false;
        });

        try {
            $template = $twig->load('@APYDataGrid/blocks.html.twig');
            $column = new TextColumn(['id' => 'name', 'filterable' => false]);
            $context = ['grid' => ['hash' => 'example'], 'column' => $column, 'value' => '<b>A B</b>'];

            $this->assertSame('&lt;b&gt;A B&lt;/b&gt;', $template->renderBlock('grid_column_cell', $context));
            $column->setSafe(false);
            $this->assertSame('<b>A B</b>', $template->renderBlock('grid_column_cell', $context));

            $column->setFilterable(true);
            $column->setSearchOnClick(true);
            $context['sourceValue'] = 'A & B';
            $this->assertSame(
                '<a href="?example[name][from]=A%20%26%20B" class="searchOnClick">&lt;b&gt;A B&lt;/b&gt;</a>',
                $template->renderBlock('grid_column_cell', $context)
            );

            $pager = $template->renderBlock('grid_pager_selectpage', [
                'grid' => ['hash' => 'example', 'page' => 1, 'pageCount' => 3],
            ]);
            $this->assertSame(2, substr_count($pager, '/><input'));
            $this->assertSame([], $deprecations);
        } finally {
            restore_error_handler();
        }
    }
}
