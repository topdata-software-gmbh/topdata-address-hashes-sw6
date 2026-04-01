<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1760000001FixFingerprintCollation extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'ALTER TABLE `tdah_address_hash`
                MODIFY COLUMN `fingerprint` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
