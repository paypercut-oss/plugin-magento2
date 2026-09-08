<?php
declare(strict_types=1);

namespace Paypercut\Payment\Controller\Adminhtml\Telemetry;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Paypercut\Payment\Model\Telemetry\DebugSessionManager;

/**
 * Reports session state, and doubles as the delivery trigger while the panel is open.
 */
class Status extends Action implements HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'Paypercut_Payment::telemetry';

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var DebugSessionManager
     */
    private $manager;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param DebugSessionManager $manager
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        DebugSessionManager $manager
    ) {
        parent::__construct($context);

        $this->jsonFactory = $jsonFactory;
        $this->manager = $manager;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->manager->status();

        $response = $this->jsonFactory->create();
        $response->setHttpResponseCode($result['status']);

        return $response->setData([
            'success' => $result['ok'],
            'data' => $result['data'],
        ]);
    }
}
