<?php
declare(strict_types=1);

namespace Paypercut\Payment\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Paypercut\Payment\Model\Telemetry\Store;

/**
 * Removes every trace of a debug session when the module is uninstalled.
 *
 * A stored telemetry token cannot be revoked from anywhere, so uninstall has to
 * destroy it rather than let it expire. The sent log goes too: it is the local
 * copy of what this store transmitted, and nothing should keep it once the
 * module is gone. The two locks are Magento database locks and are released
 * with their connection, so there is nothing to clean up for them.
 */
class Uninstall implements UninstallInterface
{
    /**
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $connection = $setup->getConnection();

        $connection->delete(
            $setup->getTable('flag'),
            [
                'flag_code IN (?)' => [
                    Store::TOKEN_KEY,
                    Store::QUEUE_KEY,
                    Store::INFLIGHT_KEY,
                    Store::RUNTIME_KEY,
                    Store::SENT_LOG_KEY,
                ],
            ]
        );

        // Every scope at once: the record is written at default scope, but a
        // multi-website store may hold overrides.
        $connection->delete(
            $setup->getTable('core_config_data'),
            ['path = ?' => Store::RECORD_CONFIG_PATH]
        );

        $setup->endSetup();
    }
}
