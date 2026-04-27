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
            $built[] = basename($htmlPath);
        }

        return $built;
    }
}
