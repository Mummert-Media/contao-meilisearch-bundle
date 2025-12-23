<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\FilesModel;

class MeilisearchImageHelper
{
    public function __construct(
        private readonly $imageStudio
    ) {}

    public function getImagePathFromUuid(string $uuid): ?string
    {
        $file = FilesModel::findByUuid($uuid);

        if ($file === null) {
            return null;
        }

        // SVG → nicht skalieren
        if (str_ends_with(strtolower($file->path), '.svg')) {
            return '/' . ltrim($file->path, '/');
        }

        $sizeId = (int) Config::get('meilisearch_imagesize');

        if ($sizeId <= 0) {
            return '/' . ltrim($file->path, '/');
        }

        $figure = $this->imageStudio
            ->createFigureBuilder()
            ->fromUuid($uuid)
            ->setSize($sizeId)
            ->build();

        $image = $figure->getImage();

        return $image ? $image->getImageSrc() : null;
    }
}