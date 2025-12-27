<?php

namespace MummertMedia\ContaoMeilisearchBundle\Controller\FrontendModule;

use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\CoreBundle\ServiceAnnotation\Asset;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Asset('css/meilisearch.css')]
class MeilisearchSearchController extends AbstractFrontendModuleController
{
    protected function getResponse(
        FragmentTemplate $template,
        ModuleModel $model,
        Request $request
    ): Response {
        $template->set('meiliLimit', (int) ($model->meiliLimit ?: 50));

        // Konfiguration sauber im Controller lesen
        $template->set('meiliHost', Config::get('meilisearch_host'));
        $template->set('meiliIndex', Config::get('meilisearch_index'));
        $template->set('meiliSearchKey', Config::get('meilisearch_api_search'));

        return $template->getResponse();
    }
}