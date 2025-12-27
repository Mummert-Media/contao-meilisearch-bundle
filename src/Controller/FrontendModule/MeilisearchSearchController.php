<?php

namespace MummertMedia\ContaoMeilisearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MeilisearchSearchController extends AbstractFrontendModuleController
{
    protected function getResponse(
        FragmentTemplate $template,
        ModuleModel $model,
        Request $request
    ): Response {
        // Variablen an Twig übergeben
        $template->set('meiliLimit', (int) ($model->meiliLimit ?: 50));

        return $template->getResponse();
    }
}