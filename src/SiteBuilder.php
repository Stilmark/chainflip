<?php

declare(strict_types=1);

final class SiteBuilder
{
    private string $tablesDir;
    private string $siteDir;

    public function __construct(string $tablesDir, string $siteDir)
    {
        $this->tablesDir = rtrim($tablesDir, '/');
        $this->siteDir = rtrim($siteDir, '/');
    }

    public function build(): array
    {
        $built = [];

        $built[] = $this->buildPage('status.html', [
            'status-current' => 'status/current.md',
            'status-latest-day' => 'status/latestDay.md',
            'status-by-day' => 'status/byDay.md',
        ]);

        $built[] = $this->buildPage('performance.html', [
            'performance-rungs' => 'performance/rungs.md',
            'performance-scalp' => 'performance/scalp.md',
            'performance-strategy' => 'performance/strategy.md',
        ]);

        $built[] = $this->buildPage('prediction.html', [
            'prediction-current' => 'prediction/current.md',
            'prediction-latest-day' => 'prediction/latestDay.md',
            'prediction-by-day' => 'prediction/byDay.md',
        ]);

        $built[] = $this->buildPage('trades.html', [
            'trades-latest' => 'trades/latest.md',
            'trades-by-day' => 'trades/byDay.md',
        ]);

        $built[] = $this->buildPage('distribution.html', [
            'distribution-bucket' => 'distribution/bucketLatestDay.md',
        ]);

        $built[] = $this->buildPage('config.html', [
            'config-rungs' => 'config/rungs.md',
        ]);

        return array_filter($built);
    }

    private function buildPage(string $htmlFile, array $tableMap): ?string
    {
        $htmlPath = $this->siteDir . '/' . $htmlFile;
        if (!file_exists($htmlPath)) {
            return null;
        }

        $html = file_get_contents($htmlPath);

        foreach ($tableMap as $containerId => $mdFile) {
            $mdPath = $this->tablesDir . '/' . $mdFile;
            if (!file_exists($mdPath)) {
                continue;
            }

            $mdContent = file_get_contents($mdPath);
            $tableHtml = $this->markdownTableToHtml($mdContent);

            $pattern = '/<div class="table-container" id="' . preg_quote($containerId, '/') . '"><\/div>/';
            $replacement = '<div class="table-container" id="' . $containerId . '">' . "\n" . $tableHtml . "\n" . '</div>';
            $html = preg_replace($pattern, $replacement, $html);

            $patternWithContent = '/<div class="table-container" id="' . preg_quote($containerId, '/') . '">[\s\S]*?<\/div>/';
            $html = preg_replace($patternWithContent, $replacement, $html);
        }

        file_put_contents($htmlPath, $html);

        return $htmlFile;
    }

    private function markdownTableToHtml(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $output = '';
        $tableLines = [];
        $pendingHeading = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#{1,3}\s+(.+)$/', $trimmed, $m)) {
                if (!empty($tableLines)) {
                    $output .= $this->renderTable($tableLines, $pendingHeading);
                    $tableLines = [];
                }
                $pendingHeading = $m[1];
                continue;
            }

            if (empty($trimmed) || $trimmed === '---') {
                if (!empty($tableLines)) {
                    $output .= $this->renderTable($tableLines, $pendingHeading);
                    $tableLines = [];
                    $pendingHeading = null;
                }
                continue;
            }

            if (strpos($trimmed, '|') !== false) {
                $tableLines[] = $trimmed;
            }
        }

        if (!empty($tableLines)) {
            $output .= $this->renderTable($tableLines, $pendingHeading);
        }

        return $output;
    }

    private function renderTable(array $lines, ?string $heading): string
    {
        $html = '';

        $headerCells = [];
        $bodyRows = [];
        $footerRow = null;
        $isHeader = true;

        foreach ($lines as $line) {
            if (preg_match('/^\|[\s\-:|]+\|$/', $line)) {
                continue;
            }

            $cells = $this->parseCells($line);

            if ($isHeader) {
                $headerCells = $cells;
                $isHeader = false;
            } else {
                $firstCell = $cells[0] ?? '';
                $isTotal = strpos($firstCell, '**Total') !== false || $firstCell === 'Total';
                if ($isTotal) {
                    $footerRow = $cells;
                } else {
                    $bodyRows[] = $cells;
                }
            }
        }

        $colCount = count($headerCells);
        $isInfoTable = $colCount === 2 && ($headerCells[0] === 'Field' || count($bodyRows) <= 5);

        if ($isInfoTable) {
            $html .= '<div class="status-bar">';
            if ($heading !== null) {
                $html .= '<div class="status-bar-title">' . $this->escapeHtml($heading) . '</div>';
            }
            foreach ($bodyRows as $row) {
                $label = $row[0] ?? '';
                $value = $row[1] ?? '';
                $html .= '<div class="status-item"><span class="status-label">' . $this->escapeHtml($label) . '</span><span class="status-value">' . $this->escapeHtml($value) . '</span></div>';
            }
            $html .= '</div>' . "\n";
            return $html;
        }

        if ($heading !== null) {
            $html .= '<h3>' . $this->escapeHtml($heading) . '</h3>' . "\n";
        }

        $tableClass = 'data-table';

        $html .= '<table class="' . $tableClass . '"><thead><tr>';
        foreach ($headerCells as $cell) {
            $html .= '<th>' . $this->escapeHtml($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($bodyRows as $row) {
            while (count($row) < $colCount) {
                $row[] = '';
            }
            $row = array_slice($row, 0, $colCount);

            $html .= '<tr>';
            foreach ($row as $cell) {
                $isBold = strpos($cell, '**') !== false;
                $cell = str_replace('**', '', $cell);
                if ($isBold) {
                    $html .= '<td><strong>' . $this->escapeHtml($cell) . '</strong></td>';
                } else {
                    $html .= '<td>' . $this->escapeHtml($cell) . '</td>';
                }
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>';

        if ($footerRow !== null) {
            while (count($footerRow) < $colCount) {
                $footerRow[] = '';
            }
            $footerRow = array_slice($footerRow, 0, $colCount);

            $html .= '<tfoot><tr>';
            foreach ($footerRow as $cell) {
                $cell = str_replace('**', '', $cell);
                $html .= '<td><strong>' . $this->escapeHtml($cell) . '</strong></td>';
            }
            $html .= '</tr></tfoot>';
        }

        $html .= '</table>' . "\n";

        return $html;
    }

    private function parseCells(string $line): array
    {
        $parts = explode('|', $line);
        $cells = [];
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $cells[] = trim($parts[$i]);
        }
        return $cells;
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
