<?php
namespace Paypercut\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class Environment
 */
class Environment implements OptionSourceInterface
{
    const SANDBOX = 'sandbox';
    const PRODUCTION = 'production';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::SANDBOX,
                'label' => __('Sandbox')
            ],
            [
                'value' => self::PRODUCTION,
                'label' => __('Production')
            ]
        ];
    }
}

