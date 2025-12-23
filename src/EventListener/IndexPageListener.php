<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        if (PHP_SAPI === 'cli') {
            echo "\n=== HARD OVERRIDE TEST ===\n";
            echo "URL: " . ($set['url'] ?? '-') . "\n";
        }

        // 🔥 HART GESETZT – KEINE LOGIK
        $set['priority']  = 9;
        $set['keywords']  = 'HARDCODED_KEYWORDS_TEST';
        $set['imagepath'] = 'HARDCODED-IMAGE-UUID-123';
        $set['startDate'] = 1234567890;

        if (PHP_SAPI === 'cli') {
            echo "SET WRITTEN:\n";
            var_dump([
                'priority'  => $set['priority'],
                'keywords'  => $set['keywords'],
                'imagepath' => $set['imagepath'],
                'startDate' => $set['startDate'],
            ]);
            echo "=== END HARD OVERRIDE ===\n";
        }
    }
}