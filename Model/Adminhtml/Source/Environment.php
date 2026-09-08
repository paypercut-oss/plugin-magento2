<?php
namespace Paypercut\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Paypercut\Payment\Model\Support\Environment as EnvironmentResolver;

/**
 * Class Environment
 *
 * Picks which Paypercut environment this store is connected to. The payment API
 * host and the telemetry edge host are both derived from this one value.
 */
class Environment implements OptionSourceInterface
{
    const DEV = EnvironmentResolver::DEV;
    const STAGE = EnvironmentResolver::STAGE;
    const PRODUCTION = EnvironmentResolver::PRODUCTION;

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::PRODUCTION,
                'label' => __('Production')
            ],
            [
                'value' => self::STAGE,
                'label' => __('Stage')
            ],
            [
                'value' => self::DEV,
                'label' => __('Development')
            ]
        ];
    }
}
