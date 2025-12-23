<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Image\Studio\Studio;

class MeilisearchImageHelper
{
    public function __construct(
        private readonly Studio $studio,
        private readonly VirtualFilesystemInterface $filesystem,
    ) {}

    /**
     * UUID → finaler Bildpfad für tl_search.imagepath
     */
    public function getImagePathFromUuid(string $uuid): ?string
    {
        try {
            $file = $this->filesystem->read($uuid);

            if ($file === null || !$file->isFile()) {
                return null;
            }

            $path = $file->getPath();

            // -------------------------
            // SVG → niemals skalieren
            // -------------------------
            if (str_ends_with(strtolower($path), '.svg')) {
                return '/' . ltrim($path, '/');
            }

            // -------------------------
            // Rasterbild → Image Studio
            // -------------------------
            $sizeId = Config::get('meilisearch_imagesize');

            if (!$sizeId) {
                return '/' . ltrim($path, '/');
            }

            $figure = $this->studio
                ->createFigureBuilder()
                ->fromFile($file)
                ->setSize($sizeId)
                ->build();

            $image = $figure->getImage();

            return $image?->getUrl();

        } catch (\Throwable) {
            return null;
        }
    }
}