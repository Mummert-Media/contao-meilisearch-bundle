<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CoreBundle\ServiceAnnotation\Hook;
use MummertMedia\ContaoMeilisearchBundle\Service\SearchDataProvider;
use Psr\Log\LoggerInterface;

class IndexPageListener
{
    public function __construct(
        private readonly SearchDataProvider $dataProvider,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @Hook("indexPage")
     */
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // Log into Symfony / Monolog
        $this->logger->info('[MEILI] onIndexPage fired', [
            'type' => $set['type'] ?? null,
            'set'  => $set,
        ]);

        $searchData = $this->dataProvider->getSearchData($set);

        $this->logger->info('[MEILI] provider result', [
            'result' => $searchData,
        ]);

        if ($searchData === null) {
            return;
        }

        $data['priority'] = (int) $searchData['priority'];
        $data['keywords'] = (string) $searchData['keywords'];

        $this->logger->info('[MEILI] tl_search updated', [
            'priority' => $data['priority'],
            'keywords' => $data['keywords'],
        ]);
    }
}