<?php
namespace Paypercut\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Paypercut\Payment\Cron\BnplStatusCheck;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventRecorder;
use Psr\Log\LoggerInterface;

/**
 * Class Success
 * Handles return from Paypercut after successful payment
 */
class Success implements HttpGetActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var PageFactory
     */
    private $pageFactory;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var BnplStatusCheck
     */
    private $bnplStatusCheck;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventRecorder
     */
    private $recorder;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param PageFactory $pageFactory
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param BnplStatusCheck $bnplStatusCheck
     * @param LoggerInterface $logger
     * @param EventRecorder $recorder
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        PageFactory $pageFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        BnplStatusCheck $bnplStatusCheck,
        LoggerInterface $logger,
        EventRecorder $recorder
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->pageFactory = $pageFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->bnplStatusCheck = $bnplStatusCheck;
        $this->logger = $logger;
        $this->recorder = $recorder;
    }

    /**
     * Execute success handler
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        try {
            $transactionId = $this->request->getParam('transaction_id');
            $orderId = $this->request->getParam('order_id');

            $this->logger->info('Paypercut payment success return', [
                'transaction_id' => $transactionId,
                'order_id' => $orderId
            ]);

            // For BNPL orders, check status immediately on return
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getId()) {
                $this->recorder->record(
                    Event::failure('checkout.return.unverifiable', 'no_order_context')
                );
            } else {
                $payment = $order->getPayment();
                $method = $payment ? (string) $payment->getMethod() : '';

                if ($method === 'paypercut_bnpl' && $order->getState() === Order::STATE_PENDING_PAYMENT) {
                    $this->logger->info('Paypercut: Checking BNPL status on success return', [
                        'order_id' => $order->getIncrementId()
                    ]);

                    try {
                        $this->bnplStatusCheck->checkOrderStatus($order);
                    } catch (\Exception $e) {
                        $this->recorder->record(
                            Event::failure('checkout.return.unverifiable', 'lookup_failed', [
                                'method' => $method,
                                'order_status' => (string) $order->getStatus()
                            ], $e)->about(['order_ref' => (string) $order->getIncrementId()])
                        );
                    }
                }

                // The shopper is back but nothing has confirmed the payment
                // yet: the webhook or the BNPL poller still owns the outcome.
                if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                    $this->recorder->record(
                        Event::of('checkout.return.pending', [
                            'method' => $method,
                            'order_status' => (string) $order->getStatus()
                        ])->about([
                            'order_ref' => (string) $order->getIncrementId(),
                            'payment_id' => (string) ($payment ? $payment->getAdditionalInformation('paypercut_id') : '')
                        ])
                    );
                }
            }

            // Redirect to standard success page
            $redirect->setPath('checkout/onepage/success');

        } catch (\Exception $e) {
            $this->logger->error('Paypercut success error: ' . $e->getMessage());
            $redirect->setPath('checkout/cart');
        }

        return $redirect;
    }
}
