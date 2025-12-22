<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        echo PHP_EOL;
        echo '================ MEILI INDEX DEBUG ================' . PHP_EOL;

        echo '--- CONTENT (first 500 chars) ---------------------' . PHP_EOL;
        echo substr(strip_tags($content), 0, 500) . PHP_EOL;

        echo '--- DATA (tl_search record, BEFORE write) ---------' . PHP_EOL;
        print_r($data);

        echo '--- SET (context) ---------------------------------' . PHP_EOL;
        print_r($set);

        echo '==================================================' . PHP_EOL;
        echo PHP_EOL;
    }
}