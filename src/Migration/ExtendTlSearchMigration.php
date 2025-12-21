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

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('tl_search');

        return
            !isset($columns['keywords']) ||
            !isset($columns['priority']) ||
            !isset($columns['imagepath']) ||
            !isset($columns['startdate']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(<<<SQL
ALTER TABLE tl_search
    ADD keywords varchar(255) NOT NULL DEFAULT '',
    ADD priority int(1) NOT NULL DEFAULT 2,
    ADD imagepath varchar(512) NOT NULL DEFAULT '',
    ADD startDate bigint(20) NOT NULL DEFAULT 0
SQL);

        return new MigrationResult(
            true,
            'Extended tl_search with Meilisearch fields.'
        );
    }
}