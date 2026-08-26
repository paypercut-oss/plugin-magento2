<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Module\ModuleListInterface;

/**
 * The store's enabled third-party modules, for correlating a failure with a conflict.
 *
 * Names and versions only. A module name is public — it is what Composer and
 * the Marketplace serve it under — but its author and path are not needed to
 * reproduce a conflict.
 *
 * Magento's own `Magento_*` modules are excluded: a stock install has several
 * hundred of them, they are implied by `magento_version` in the snapshot, and
 * sending them would crowd the queue with the one thing support never needs to
 * compare between stores.
 */
class ActiveModules
{
    const CORE_PREFIX = 'Magento_';

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @param ModuleListInterface $moduleList
     */
    public function __construct(ModuleListInterface $moduleList)
    {
        $this->moduleList = $moduleList;
    }

    /**
     * @return array<string, string> name => version, sorted by name.
     */
    public function values(): array
    {
        $modules = [];

        foreach ($this->moduleList->getAll() as $name => $module) {
            $name = (string) $name;

            if ($name === '' || strpos($name, self::CORE_PREFIX) === 0) {
                continue;
            }

            $modules[$name] = (string) ($module['setup_version'] ?? '');
        }

        ksort($modules);

        return $modules;
    }
}
