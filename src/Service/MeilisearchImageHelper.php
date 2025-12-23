<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\CoreBundle\Filesystem\FilesystemInterface;
use Contao\Image\Studio;
use Contao\Config;

class MeilisearchImageHelper
{
    public function __construct(
        private readonly FilesystemInterface $filesStorage,
        private readonly Studio $imageStudio,
    ) {}

    public function getImagePathFromUuid(string $uuid): ?string
    {
        // UUID → Datei
        if (!$this->filesStorage->fileExists($uuid)) {
            return null;
        }

        $file = $this->filesStorage->get($uuid);
        $path = $file->getPath();

        // SVG: nicht skalieren
        if (str_ends_with(strtolower($path), '.svg')) {
            return '/' . ltrim($path, '/');
        }

        // Bildgröße aus tl_settings
        $sizeId = (int) Config::get('meilisearch_imagesize');
        if ($sizeId <= 0) {
            return '/' . ltrim($path, '/');
        }

        // Bild über Image Studio erzeugen
        $figure = $this->imageStudio
            ->createFigure($uuid)
            ->setSize($sizeId)
            ->build();

        return $figure?->getImage()?->getSrc() ?? null;
    }
}