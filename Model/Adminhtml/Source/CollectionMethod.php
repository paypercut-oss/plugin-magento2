<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CollectionMethod implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'charge_automatically', 'label' => __('Charge Automatically')],
            ['value' => 'send_invoice', 'label' => __('Send Invoice')]
        ];
    }
}

