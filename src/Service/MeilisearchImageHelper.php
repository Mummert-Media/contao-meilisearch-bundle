<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\FilesModel;

class MeilisearchImageHelper
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Studio $studio,
    ) {}

    /**
     * Wandelt eine Bild-UUID aus tl_search.imagepath
     * in einen generierten Asset-Pfad (/assets/images/…)
     */
    public function resolveImagePath(?string $uuid): ?string
    {
        if (!$uuid) {
            return null;
        }

        // Contao-Framework initialisieren (CLI & Frontend)
        try {
            $this->framework->initialize();
        } catch (\Throwable $e) {
            error_log('[ContaoMeilisearch] ImageHelper: Framework init failed: ' . $e->getMessage());
            return null;
        }

        /** @var FilesModel|null $file */
        try {
            $file = FilesModel::findByUuid($uuid);
        } catch (\Throwable $e) {
            error_log(
                '[ContaoMeilisearch] ImageHelper: FilesModel lookup failed (' . $uuid . '): ' . $e->getMessage()
            );
            return null;
        }

        if (!$file) {
            error_log('[ContaoMeilisearch] ImageHelper: File not found for UUID ' . $uuid);
            return null;
        }

        // ImageSize aus tl_settings
        $imageSizeId = (int) Config::get('meilisearch_imagesize');

        // Fallback: Originaldatei
        if ($imageSizeId <= 0) {
            return $file->path;
        }

        try {
            $figure = $this->studio
                ->createFigureBuilder()
                ->from($file->path)
                ->setSize($imageSizeId)
                ->build();

            $image = $figure->getImage();

            if ($image === null) {
                error_log(
                    '[ContaoMeilisearch] ImageHelper: Image generation failed for ' . $file->path
                );
                return null;
            }

            return $image->getImageSrc() ?: null;

        } catch (\Throwable $e) {
            error_log(
                '[ContaoMeilisearch] ImageHelper: Image processing failed for '
                . $file->path . ': ' . $e->getMessage()
            );
            return null;
        }
    }
}