<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataFoundationSW6\DependencyInjection\TopConfigRegistryCompilerPass;

class TopdataAddressHashesSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TopConfigRegistryCompilerPass(
            self::class,
            [
                'hashFields' => 'hashFields',
            ]
        ));
    }

    public function uninstall(\Shopware\Core\Framework\Plugin\Context\UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Only run if the user checked "Delete all data" or used the --delete flag
        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->container->get(\Doctrine\DBAL\Connection::class);

        // 1. Drop Triggers
        $connection->executeStatement('DROP TRIGGER IF EXISTS `tdah_customer_address_ins`');
        $connection->executeStatement('DROP TRIGGER IF EXISTS `tdah_customer_address_upd`');
        $connection->executeStatement('DROP TRIGGER IF EXISTS `tdah_order_address_ins`');
        $connection->executeStatement('DROP TRIGGER IF EXISTS `tdah_order_address_upd`');

        // 2. Drop Tables
        $connection->executeStatement('DROP TABLE IF EXISTS `tdah_customer_address_extension`');
        $connection->executeStatement('DROP TABLE IF EXISTS `tdah_order_address_extension`');
    }
}