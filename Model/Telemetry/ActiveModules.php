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
 *
 * A version is never an empty string: the edge discards an attribute whose
 * value is empty, and it discards the key with it, so a module without a
 * `setup_version` — which is most of them since 2.3 — would arrive as no
 * module at all, and naming the module is what this event is for.
 */
class ActiveModules
{
    /**
     * Stands in for a version the module never declared.
     */
    public const UNKNOWN_VERSION = 'unknown';

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

            $version = trim((string) ($module['setup_version'] ?? ''));

            $modules[$name] = $version === '' ? self::UNKNOWN_VERSION : $version;
        }

        ksort($modules);

        return $modules;
    }
}
