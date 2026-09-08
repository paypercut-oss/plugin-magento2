<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Module\ModuleListInterface;

/**
 * This module's own version, as reported to the edge and in the snapshot.
 */
class ModuleVersion
{
    const MODULE_NAME = 'Paypercut_Payment';

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
     * @return string
     */
    public function get(): string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);

        return (string) ($module['setup_version'] ?? '');
    }
}
