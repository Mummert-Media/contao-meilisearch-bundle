<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\FilesModel;
use Contao\Image\Studio;

class MeilisearchImageHelper
{
    public function __construct(
        private readonly Studio $imageStudio,
    ) {}

    public function getImagePathFromUuid(string $uuid): ?string
    {
        $file = FilesModel::findByUuid($uuid);

        if ($file === null) {
            return null;
        }

        $path = $file->path;

        // SVG → niemals skalieren
        if (str_ends_with(strtolower($path), '.svg')) {
            return '/' . ltrim($path, '/');
        }

        $sizeId = (int) Config::get('meilisearch_imagesize');

        if ($sizeId <= 0) {
            return '/' . ltrim($path, '/');
        }

        $figure = $this->imageStudio
            ->createFigure($uuid)
            ->setSize($sizeId)
            ->build();

        return $figure?->getImage()?->getSrc() ?? null;
    }
}