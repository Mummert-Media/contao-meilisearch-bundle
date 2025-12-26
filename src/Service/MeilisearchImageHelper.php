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
        error_log('--- MeiliImg START ---');

        if (!$uuid) {
            error_log('[MeiliImg] UUID leer → return null');
            return null;
        }

        error_log('[MeiliImg] UUID = ' . $uuid);

        // Contao-Framework initialisieren (CLI & Frontend)
        $this->framework->initialize();
        error_log('[MeiliImg] Framework initialized');

        /** @var FilesModel|null $file */
        $file = FilesModel::findByUuid($uuid);

        if (!$file) {
            error_log('[MeiliImg] FilesModel::findByUuid() = NULL');
            return null;
        }

        error_log('[MeiliImg] FilesModel gefunden');
        error_log('[MeiliImg] file->path = ' . $file->path);
        error_log('[MeiliImg] file->uuid = ' . ($file->uuid ?? '(n/a)'));

        // ImageSize aus tl_settings
        $rawSize = Config::get('meilisearch_imagesize');
        $imageSizeId = (int) $rawSize;

        error_log('[MeiliImg] meilisearch_imagesize raw = ' . var_export($rawSize, true));
        error_log('[MeiliImg] meilisearch_imagesize int = ' . $imageSizeId);

        // Fallback: Originaldatei
        if ($imageSizeId <= 0) {
            error_log('[MeiliImg] imageSizeId <= 0 → FALLBACK file->path = ' . $file->path);
            error_log('--- MeiliImg END ---');
            return $file->path;
        }

        try {
            $builder = $this->studio
                ->createFigureBuilder()
                ->from($file->path)
                ->setSize($imageSizeId);

            error_log('[MeiliImg] FigureBuilder erstellt (from=' . $file->path . ', size=' . $imageSizeId . ')');

            $figure = $builder->build();
            error_log('[MeiliImg] Figure build() OK');

            $image = $figure->getImage();

            if ($image === null) {
                error_log('[MeiliImg] figure->getImage() = NULL');
                return null;
            }

            $src = $image->getImageSrc();

            error_log('[MeiliImg] image->getImageSrc() = ' . $src);

            return $src ?: null;

        } catch (\Throwable $e) {
            error_log('[MeiliImg] EXCEPTION ' . get_class($e) . ': ' . $e->getMessage());
            error_log('--- MeiliImg END ---');
            return null;
        }
    }
}