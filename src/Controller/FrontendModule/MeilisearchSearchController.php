<?php

namespace MummertMedia\ContaoMeilisearchBundle\Controller\FrontendModule;

use Contao\FrontendModule;

class MeilisearchSearchController extends FrontendModule
{
    /**
     * Template
     */
    protected $strTemplate = 'mod_meilisearch_search';

    /**
     * Compile the frontend module
     */
    protected function compile(): void
    {
        // Fallback, falls das Feld leer ist
        $limit = (int) ($this->meiliLimit ?: 50);

        $this->Template->meiliLimit = $limit;
    }
}