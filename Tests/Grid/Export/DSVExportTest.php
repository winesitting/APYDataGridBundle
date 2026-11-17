<?php

namespace APY\DataGridBundle\Tests\Grid\Export;

use APY\DataGridBundle\Grid\Column\TextColumn;
use APY\DataGridBundle\Grid\Columns;
use APY\DataGridBundle\Grid\Export\SCSVExport;
use APY\DataGridBundle\Grid\Grid;
use APY\DataGridBundle\Grid\Row;
use APY\DataGridBundle\Grid\Rows;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class DSVExportTest extends TestCase
{
    /** @dataProvider provideOptions */
    public function testCsvPreservesTitlesEscapingOrderAndHiddenColumns($titles, $bom): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $columns = new Columns($authorization);
        $columns->addColumn(new TextColumn(['id' => 'name', 'title' => 'Name']));
        $columns->addColumn(new TextColumn(['id' => 'detail', 'title' => 'Detail']));
        $columns->addColumn(new TextColumn(['id' => 'hidden', 'visible' => false, 'export' => false]));
        $row = new Row();
        $row->setField('name', '<b>Alpha</b> &amp; Beta');
        $row->setField('detail', 'Text; "quoted"');
        $row->setField('hidden', 'private');
        $grid = $this->createMock(Grid::class);
        $grid->method('getRows')->willReturn(new Rows([$row]));
        $grid->method('getColumns')->willReturn($columns);
        $grid->method('isTitleSectionVisible')->willReturn($titles);
        $export = new class('test', 'test', ['withBOM' => $bom]) extends SCSVExport {
            protected function getGridTitles()
            {
                return ['name' => 'Name', 'detail' => 'Detail'];
            }

            protected function getGridCell($column, $row)
            {
                return $row->getField($column->getId());
            }
        };
        $export->computeData($grid);
        $expected = ($bom ? "\xEF\xBB\xBF" : '').($titles ? "Name;Detail\n" : '')."\"Alpha & Beta\";\"Text; \"\"quoted\"\"\"\n";
        $this->assertSame($expected, $export->getContent());
    }

    public function provideOptions(): array
    {
        return [[true, true], [true, false], [false, true], [false, false]];
    }

    public function testEmptyCsvKeepsTheExpectedHeader(): void
    {
        $grid = $this->createMock(Grid::class);
        $grid->method('getRows')->willReturn(new Rows());
        $grid->method('isTitleSectionVisible')->willReturn(true);
        $export = new class('test') extends SCSVExport {
            protected function getGridTitles()
            {
                return ['id' => 'ID'];
            }
        };
        $export->computeData($grid);
        $this->assertSame("\xEF\xBB\xBFID\n", $export->getContent());
    }
}
