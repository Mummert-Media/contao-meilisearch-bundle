<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CoreBundle\ServiceAnnotation\Hook;
use MummertMedia\ContaoMeilisearchBundle\Service\SearchDataProvider;

/**
 * IndexPage Listener for Contao search index
 */
class IndexPageListener
{
    public function __construct(
        private readonly SearchDataProvider $dataProvider
    ) {}

    /**
     * This hook is executed for every indexed document
     *
     * @Hook("indexPage")
     */
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // ---------------------------------------------------------------------
        // DEBUG: prove that the hook is executed (CLI + Frontend safe)
        // ---------------------------------------------------------------------
        error_log('[MEILI] onIndexPage fired');

        // log basic context
        error_log('[MEILI] type=' . ($set['type'] ?? 'NULL'));
        error_log('[MEILI] set=' . json_encode($set, JSON_UNESCAPED_SLASHES));

        // ---------------------------------------------------------------------
        // Fetch search data (priority + keywords)
        // ---------------------------------------------------------------------
        $searchData = $this->dataProvider->getSearchData($set);

        // log provider result
        error_log('[MEILI] provider result=' . json_encode($searchData));

        if ($searchData === null) {
            error_log('[MEILI] no search data resolved');
            return;
        }

        // ---------------------------------------------------------------------
        // Write into tl_search (this array IS the DB record)
        // ---------------------------------------------------------------------
        $data['priority'] = (int) $searchData['priority'];
        $data['keywords'] = (string) $searchData['keywords'];

        // final confirmation
        error_log('[MEILI] tl_search updated: priority='
            . $data['priority']
            . ' keywords="' . $data['keywords'] . '"'
        );
    }
}