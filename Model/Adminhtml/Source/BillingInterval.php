<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Adminhtml\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Data\OptionSourceInterface;

class BillingInterval extends AbstractSource implements OptionSourceInterface
{
    /**
     * Get all options
     *
     * @return array
     */
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => '', 'label' => __('-- Use Default --')],
                ['value' => 'day', 'label' => __('Daily')],
                ['value' => 'week', 'label' => __('Weekly')],
                ['value' => 'month', 'label' => __('Monthly')],
                ['value' => 'year', 'label' => __('Yearly')]
            ];
        }
        return $this->_options;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return $this->getAllOptions();
    }
}
