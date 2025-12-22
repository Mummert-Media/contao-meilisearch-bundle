<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use MummertMedia\ContaoMeilisearchBundle\Service\SearchDataProvider;

class IndexPageListener
{
    public function __construct(
        private readonly SearchDataProvider $dataProvider
    ) {}

    public function __invoke(string $content, array &$data, array &$set): void
    {

        file_put_contents(
            TL_ROOT . '/var/logs/meili-debug.log',
            json_encode($set, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );
        $searchData = $this->dataProvider->getSearchData($set);

        if ($searchData === null) {
            return;
        }

        // landet direkt in tl_search
        $data['priority'] = $searchData['priority'];
        $data['keywords'] = $searchData['keywords'];
    }
}