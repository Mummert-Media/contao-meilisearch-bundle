<?php

namespace MummertMedia\ContaoMeilisearchBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\CalendarBundle\ContaoCalendarBundle;
use Contao\NewsBundle\ContaoNewsBundle;
use MummertMedia\ContaoMeilisearchBundle\MummertMediaContaoMeilisearchBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser)
    {
        return [
            BundleConfig::create(MummertMediaContaoMeilisearchBundle::class)
                ->setLoadAfter([
                    ContaoCoreBundle::class,
                    ContaoCalendarBundle::class,
                    ContaoNewsBundle::class,
                ]),
        ];
    }
}