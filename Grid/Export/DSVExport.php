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

namespace APY\DataGridBundle\Grid\Export;

use APY\DataGridBundle\Grid\Grid;

/**
 * Delimiter-Separated Values.
 */
class DSVExport extends Export
{
    protected $fileExtension = null;

    protected $mimeType = 'application/octet-stream';

    protected $delimiter = '';

    protected $withBOM = true;

    public function __construct($title, $fileName = 'export', $params = [], $charset = 'UTF-8')
    {
        $this->delimiter = isset($params['delimiter']) ? $params['delimiter'] : $this->delimiter;
        $this->withBOM = isset($params['withBOM']) ? $params['withBOM'] : $this->withBOM;

        parent::__construct($title, $fileName, $params, $charset);
    }

    public function computeData(Grid $grid)
    {
        $this->grid = $grid;
        $outstream = fopen('php://temp', 'r+');

        if ($grid->isTitleSectionVisible()) {
            fputcsv($outstream, $this->getGridTitles(), $this->delimiter, '"', '\\');
        }

        foreach ($grid->getRows() as $row) {
            $line = [];
            foreach ($grid->getColumns() as $column) {
                if ($column->isVisible(true)) {
                    $line[$column->getId()] = $this->cleanHTML($this->getGridCell($column, $row));
                }
            }
            fputcsv($outstream, $line, $this->delimiter, '"', '\\');
        }

        rewind($outstream);

        $content = ($this->withBOM ? "\xEF\xBB\xBF" : '').stream_get_contents($outstream);

        fclose($outstream);

        $this->content = $content;
    }

    /**
     * get delimiter.
     *
     * @return string
     */
    public function getDelimiter()
    {
        return $this->delimiter;
    }

    /**
     * set delimiter.
     *
     * @param string $delimiter
     *
     * @return self
     */
    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    /**
     * get BOM setting.
     *
     * @return string
     */
    public function getWithBOM()
    {
        return $this->withBOM;
    }

    /*** set BOM setting.
     *
     * @param string $withBOM
     *
     * @return self
     */
    public function setWithBOM($withBOM)
    {
        $this->withBOM = $withBOM;

        return $this;
    }
}
