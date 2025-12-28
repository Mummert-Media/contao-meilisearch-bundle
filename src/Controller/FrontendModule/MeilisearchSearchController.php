<?php

namespace MummertMedia\ContaoMeilisearchBundle\Controller\FrontendModule;

use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MeilisearchSearchController extends AbstractFrontendModuleController
{
    protected function getResponse(
        $template,
        ModuleModel $model,
        Request $request
    ): Response {
        // Beide Template-Typen unterstützen Property-Zugriff
        $template->meiliLimit     = (int) ($model->meiliLimit ?: 50);
        $template->meiliHost      = Config::get('meilisearch_host');
        $template->meiliIndex     = Config::get('meilisearch_index');
        $template->meiliSearchKey = Config::get('meilisearch_api_search');

        return $template->getResponse();
    }
}