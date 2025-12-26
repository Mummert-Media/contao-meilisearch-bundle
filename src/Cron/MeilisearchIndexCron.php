<?php
namespace MummertMedia\ContaoMeilisearchBundle\Cron;

use Contao\CoreBundle\Framework\ContaoFramework;
use MummertMedia\ContaoMeilisearchBundle\Service\MeilisearchIndexService;

class MeilisearchIndexCron
{
    public function __construct(
        private readonly MeilisearchIndexService $indexService,
        private readonly ContaoFramework $framework,
    ) {}

    public function __invoke(): void
    {
        // Contao initialisieren (wichtig!)
        $this->framework->initialize();

        // einmal täglich indexieren
        $this->indexService->run();
    }
}