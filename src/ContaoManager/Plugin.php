<?php

namespace MummertMedia\ContaoMeilisearchBundle\ContaoManager;

use Contao\CalendarBundle\ContaoCalendarBundle;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\NewsBundle\ContaoNewsBundle;
use MummertMedia\ContaoMeilisearchBundle\ContaoMeilisearchBundle;

class Plugin implements BundlePluginInterface
{
    /**
     * @return BundleConfig[]
     */
    public function getBundles(ParserInterface $parser)
    {
        return [
            BundleConfig::create(ContaoMeilisearchBundle::class)
                ->setLoadAfter([
                    ContaoCoreBundle::class,
                    ContaoCalendarBundle::class,
                    ContaoNewsBundle::class,
                ]),
        ];
    }
}