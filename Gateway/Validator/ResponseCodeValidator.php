<?php
namespace Paypercut\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Paypercut\Payment\Gateway\Http\Client\TransactionClient;

/**
 * Class ResponseCodeValidator
 */
class ResponseCodeValidator extends AbstractValidator
{
    const RESULT_CODE = 'RESULT_CODE';

    /**
     * @param ResultInterfaceFactory $resultFactory
     */
    public function __construct(ResultInterfaceFactory $resultFactory)
    {
        parent::__construct($resultFactory);
    }

    /**
     * Performs validation of result code
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        if (!isset($validationSubject['response']) || !is_array($validationSubject['response'])) {
            throw new \InvalidArgumentException('Response does not exist');
        }

        $response = $validationSubject['response'];

        if ($this->isSuccessfulTransaction($response)) {
            return $this->createResult(
                true,
                []
            );
        } else {
            return $this->createResult(
                false,
                [__('Gateway rejected the transaction.')]
            );
        }
    }

    /**
     * Check if transaction is successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulTransaction(array $response)
    {
        return isset($response[self::RESULT_CODE])
            && $response[self::RESULT_CODE] === TransactionClient::SUCCESS;
    }
}

