<?php
declare(strict_types=1);

namespace Paypercut\Payment\Block\Checkout;

use Magento\Framework\View\Element\Template;
use Paypercut\Payment\Model\Ui\BnplConfigProvider;

/**
 * Block that provides BNPL config for checkout template (Hyva and standard).
 * Outputs config server-side so the frontend does not depend on window.checkoutConfig.
 */
class BnplConfig extends Template
{
    /**
     * @var BnplConfigProvider
     */
    private $bnplConfigProvider;

    public function __construct(
        Template\Context $context,
        BnplConfigProvider $bnplConfigProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->bnplConfigProvider = $bnplConfigProvider;
    }

    /**
     * Get BNPL config for paypercut_bnpl (installmentOptions, etc.)
     *
     * @return array
     */
    public function getBnplConfig(): array
    {
        $config = $this->bnplConfigProvider->getConfig();
        return $config['payment'][BnplConfigProvider::CODE] ?? [];
    }
}
