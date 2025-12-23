<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\FilesModel;

class MeilisearchImageHelper
{
    private $imageStudio;

    public function __construct($imageStudio)
    {
        $this->imageStudio = $imageStudio;
    }

    public function getImagePathFromUuid(string $uuid): ?string
    {
        if (PHP_SAPI === 'cli') {
            echo "\n[MeilisearchImageHelper]\n";
            echo "UUID received: $uuid\n";
        }

        $file = FilesModel::findByUuid($uuid);

        if ($file === null) {
            if (PHP_SAPI === 'cli') {
                echo "❌ FilesModel::findByUuid() returned NULL\n";
            }
            return null;
        }

        if (PHP_SAPI === 'cli') {
            echo "✔ File found: {$file->path}\n";
        }

        // SVG niemals skalieren
        if (str_ends_with(strtolower($file->path), '.svg')) {
            $path = '/' . ltrim($file->path, '/');

            if (PHP_SAPI === 'cli') {
                echo "✔ SVG detected, returning original path:\n";
                echo "→ $path\n";
            }

            return $path;
        }

        $sizeId = (int) Config::get('meilisearch_imagesize');

        if (PHP_SAPI === 'cli') {
            echo "Image size ID from tl_settings: $sizeId\n";
        }

        if ($sizeId <= 0) {
            $path = '/' . ltrim($file->path, '/');

            if (PHP_SAPI === 'cli') {
                echo "⚠ No image size set, returning original path:\n";
                echo "→ $path\n";
            }

            return $path;
        }

        $figure = $this->imageStudio
            ->createFigureBuilder()
            ->fromUuid($uuid)
            ->setSize($sizeId)
            ->build();

        if (!$figure) {
            if (PHP_SAPI === 'cli') {
                echo "❌ Figure build returned NULL\n";
            }
            return null;
        }

        $image = $figure->getImage();

        if ($image === null) {
            if (PHP_SAPI === 'cli') {
                echo "❌ Figure->getImage() returned NULL\n";
            }
            return null;
        }

        $src = $image->getImageSrc();

        if (PHP_SAPI === 'cli') {
            echo "✔ Processed image path:\n";
            echo "→ $src\n";
        }

        return $src ?: null;
    }
}