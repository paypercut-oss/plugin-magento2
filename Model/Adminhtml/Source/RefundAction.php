<?php
namespace Paypercut\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class RefundAction
 */
class RefundAction implements OptionSourceInterface
{
    const ACTION_REFUND_ON_CREDIT_MEMO = 'refund_on_credit_memo';
    const ACTION_CREDIT_MEMO_ON_REFUND = 'credit_memo_on_refund';
    const ACTION_NONE = 'none';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::ACTION_REFUND_ON_CREDIT_MEMO,
                'label' => __('Refund on Credit Memo')
            ],
            [
                'value' => self::ACTION_CREDIT_MEMO_ON_REFUND,
                'label' => __('Credit Memo on Refund')
            ],
            [
                'value' => self::ACTION_NONE,
                'label' => __('None')
            ]
        ];
    }
}
