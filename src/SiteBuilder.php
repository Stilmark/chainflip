<?php

declare(strict_types=1);

final class SiteBuilder
{
    private string $siteDir;

    public function __construct(string $tablesDir, string $siteDir)
    {
        // tablesDir kept for backward compatibility but no longer used
        $this->siteDir = rtrim($siteDir, '/');
    }

    public function build(): array
    {
        $built = [];
        $htmlFiles = glob($this->siteDir . '/*.html') ?: [];

        foreach ($htmlFiles as $htmlPath) {
            if ($this->updateFooter($htmlPath)) {
                $built[] = basename($htmlPath);
            }
        }

        return $built;
    }

    private function updateFooter(string $htmlPath): bool
    {
        if (!file_exists($htmlPath)) {
            return false;
        }

        $html = file_get_contents($htmlPath);

        $timestamp = date('Y-m-d H:i:s') . ' UTC';
        $newHtml = preg_replace(
            '/<footer>.*?<\/footer>/s',
            '<footer>LP Parser &copy; 2026 &mdash; Updated ' . $timestamp . '</footer>',
            $html
        );

        if ($newHtml !== $html) {
            file_put_contents($htmlPath, $newHtml);
            return true;
        }

        return true;
    }
}
