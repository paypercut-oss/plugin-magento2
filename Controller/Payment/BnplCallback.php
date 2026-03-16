<?php
namespace Paypercut\Payment\Controller\Payment;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Paypercut\Payment\Cron\BnplStatusCheck;
use Psr\Log\LoggerInterface;

/**
 * Callback controller for Paypercut BNPL status notifications.
 * URL: paypercut/payment/bnplcallback
 * Accepts both GET and POST requests.
 */
class BnplCallback implements ActionInterface, CsrfAwareActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var BnplStatusCheck
     */
    private $bnplStatusCheck;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param BnplStatusCheck $bnplStatusCheck
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        OrderCollectionFactory $orderCollectionFactory,
        BnplStatusCheck $bnplStatusCheck,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->bnplStatusCheck = $bnplStatusCheck;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            // Log raw incoming request data
            $rawBody = $this->request->getContent();
            $method = $this->request->getMethod();
            $params = $this->request->getParams();

            $this->logger->info('Paypercut: BNPL callback - raw request', [
                'method' => $method,
                'raw_body' => $rawBody,
                'query_params' => $params,
                'content_type' => $this->request->getHeader('Content-Type'),
            ]);

            // Try to get attempt_id from JSON body, then query params
            $body = json_decode($rawBody, true);

            $this->logger->info('Paypercut: BNPL callback - parsed body', [
                'parsed_body' => $body,
            ]);

            $attemptId = $body['attempt_id']
                ?? $this->request->getParam('attempt_id')
                ?? null;

            if (!$attemptId) {
                $this->logger->warning('Paypercut: BNPL callback - missing attempt_id', [
                    'method' => $method,
                    'params' => $params,
                    'body' => $body,
                ]);
                $result->setHttpResponseCode(400);
                $result->setData(['success' => false, 'message' => 'Missing attempt_id']);
                return $result;
            }

            $this->logger->info('Paypercut: BNPL callback received', [
                'attempt_id' => $attemptId,
                'method' => $method,
            ]);

            $order = $this->getOrderByAttemptId($attemptId);

            if (!$order) {
                $this->logger->warning('Paypercut: BNPL callback - order not found', [
                    'attempt_id' => $attemptId,
                ]);
                $result->setHttpResponseCode(404);
                $result->setData(['success' => false, 'message' => 'Order not found']);
                return $result;
            }

            $this->logger->info('Paypercut: BNPL callback - order found, triggering status check', [
                'attempt_id' => $attemptId,
                'order_id' => $order->getIncrementId(),
                'order_state' => $order->getState(),
                'order_status' => $order->getStatus(),
            ]);

            $this->bnplStatusCheck->checkOrderStatus($order);

            $result->setData([
                'success' => true,
                'message' => 'Status check triggered',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Paypercut: BNPL callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result->setHttpResponseCode(500);
            $result->setData(['success' => false, 'message' => 'Internal error']);
        }

        return $result;
    }

    /**
     * Find the order by BNPL attempt ID stored in payment additional information
     *
     * @param string $attemptId
     * @return Order|null
     */
    private function getOrderByAttemptId(string $attemptId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->join(
            ['payment' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = payment.parent_id',
            []
        )->where(
            'payment.additional_information LIKE ?',
            '%"paypercut_id":"' . $attemptId . '"%'
        );

        /** @var Order $order */
        $order = $collection->getFirstItem();

        return $order->getId() ? $order : null;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
