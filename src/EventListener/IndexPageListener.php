<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CoreBundle\ServiceAnnotation\Hook;
use MummertMedia\ContaoMeilisearchBundle\Service\SearchDataProvider;

class IndexPageListener
{
    public function __construct(
        private readonly SearchDataProvider $dataProvider
    ) {}

    /**
     * @Hook("indexPage")
     */
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // 🔍 DEBUG – MUSS jetzt feuern
        file_put_contents(
            TL_ROOT . '/var/logs/meili-debug.log',
            json_encode($set, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );

        $searchData = $this->dataProvider->getSearchData($set);
        if ($searchData === null) {
            return;
        }

        $data['priority'] = $searchData['priority'];
        $data['keywords'] = $searchData['keywords'];
    }
}