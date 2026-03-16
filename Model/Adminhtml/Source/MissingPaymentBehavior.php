<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

class MissingPaymentBehavior implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'cancel', 'label' => __('Cancel Subscription')],
            ['value' => 'pause', 'label' => __('Pause Subscription')],
            ['value' => 'create_invoice', 'label' => __('Create Invoice')]
        ];
    }
}

