<?php

namespace MummertMedia\ContaoMeilisearchBundle\Migration;

use Contao\CoreBundle\Migration\MigrationInterface;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

class ExtendTlSearchMigration implements MigrationInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'mummert_media_extend_tl_search';
    }

    public function shouldRun(): bool
    {
        // Wir lassen MySQL entscheiden – kein Schema-Precheck
        return true;
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(<<<SQL
ALTER TABLE tl_search
    ADD COLUMN IF NOT EXISTS keywords varchar(255) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS priority int(1) NOT NULL DEFAULT 2,
    ADD COLUMN IF NOT EXISTS imagepath varchar(512) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS startDate bigint(20) NOT NULL DEFAULT 0
SQL);

        return new MigrationResult(
            true,
            'Extended tl_search with Meilisearch fields.'
        );
    }
}