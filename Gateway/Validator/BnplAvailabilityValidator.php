<?php
namespace Paypercut\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Class BnplAvailabilityValidator
 * Validates if BNPL payment is available for the current order
 */
class BnplAvailabilityValidator extends AbstractValidator
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param ResultInterfaceFactory $resultFactory
     * @param ConfigInterface $config
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        ?ConfigInterface $config = null
    ) {
        parent::__construct($resultFactory);
        $this->config = $config;
    }

    /**
     * Validates if BNPL is available
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        $isValid = true;
        $errorMessages = [];

        // Check if we have quote/order data
        if (isset($validationSubject['storeId'])) {
            $storeId = $validationSubject['storeId'];
            
            $minTotal = (float) $this->config->getValue('min_order_total', $storeId);
            $maxTotal = (float) $this->config->getValue('max_order_total', $storeId);

            if (isset($validationSubject['amount'])) {
                $amount = (float) $validationSubject['amount'];

                if ($amount < $minTotal) {
                    $isValid = false;
                    $errorMessages[] = __('Order total is below minimum for BNPL payment (%1).', $minTotal);
                }

                if ($amount > $maxTotal) {
                    $isValid = false;
                    $errorMessages[] = __('Order total exceeds maximum for BNPL payment (%1).', $maxTotal);
                }
            }
        }

        return $this->createResult($isValid, $errorMessages);
    }
}

