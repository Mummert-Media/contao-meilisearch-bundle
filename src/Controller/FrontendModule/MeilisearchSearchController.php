<?php

namespace MummertMedia\ContaoMeilisearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\AbstractFrontendModuleController;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MeilisearchSearchController extends AbstractFrontendModuleController
{
    protected function getResponse(
        ModuleModel $model,
        string $section,
        array $classes = null,
        Request $request = null
    ): Response {
        return $this->render(
            'mod_meilisearch_search.html.twig',
            [
                'meiliLimit' => (int) ($model->meiliLimit ?: 50),
            ]
        );
    }
}