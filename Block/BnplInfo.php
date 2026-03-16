<?php
namespace Paypercut\Payment\Block;

use Magento\Payment\Block\Info;

/**
 * Class BnplInfo
 * Payment info block for BNPL payments - renders in admin order view and frontend
 */
class BnplInfo extends Info
{
    /**
     * @var string
     */
    protected $_template = 'Paypercut_Payment::info/bnpl.phtml';

    /**
     * Get the BNPL attempt ID
     *
     * @return string|null
     */
    public function getAttemptId(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('paypercut_id');
    }

    /**
     * Get the BNPL status (e.g. ATTEMPT_STATUS_COMPLETED)
     *
     * @return string|null
     */
    public function getBnplStatus(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('bnpl_status');
    }

    /**
     * Get human-readable BNPL status
     *
     * @return string
     */
    public function getBnplStatusLabel(): string
    {
        $status = $this->getBnplStatus();
        if (!$status) {
            return (string) __('Awaiting confirmation');
        }

        $statusMap = [
            'ATTEMPT_STATUS_CREATED' => __('Created'),
            'ATTEMPT_STATUS_INITIALIZED' => __('Initialized'),
            'ATTEMPT_STATUS_IN_PROGRESS' => __('In Progress'),
            'ATTEMPT_STATUS_COMPLETED' => __('Completed'),
            'ATTEMPT_STATUS_ERRORED' => __('Errored'),
            'ATTEMPT_STATUS_DECLINED' => __('Declined'),
        ];

        return (string) ($statusMap[$status] ?? $status);
    }

    /**
     * Get the BNPL provider name
     *
     * @return string|null
     */
    public function getProviderName(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('bnpl_provider_name');
    }

    /**
     * Get the BNPL purchase ID
     *
     * @return string|null
     */
    public function getPurchaseId(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('bnpl_purchase_id');
    }

    /**
     * Get the provider purchase reference
     *
     * @return string|null
     */
    public function getProviderPurchaseRef(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('bnpl_provider_purchase_ref');
    }

    /**
     * Get the merchant purchase reference (order increment ID)
     *
     * @return string|null
     */
    public function getMerchantPurchaseRef(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('bnpl_merchant_purchase_ref');
    }

    /**
     * Get the status reason
     *
     * @return string|null
     */
    public function getStatusReason(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('bnpl_status_reason');
    }

    /**
     * Get installments count
     *
     * @return string|null
     */
    public function getInstallments(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('installments');
    }

    /**
     * Get monthly amount
     *
     * @return string|null
     */
    public function getMonthlyAmount(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('monthly_amount');
    }
}
