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

        // Contao-Framework sicher initialisieren
        $this->framework->initialize();

        /** @var FilesModel|null $file */
        $file = FilesModel::findByUuid($uuid);
        if (!$file) {
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
                return null;
            }

            return $image->getUrl();
        } catch (\Throwable) {
            // bewusst still – kein Bild = kein Index-Fail
            return null;
        }
    }
}