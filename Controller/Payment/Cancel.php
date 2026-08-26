<?php
namespace Paypercut\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Message\ManagerInterface;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventRecorder;
use Psr\Log\LoggerInterface;

/**
 * Class Cancel
 * Handles return from Paypercut when payment is cancelled
 */
class Cancel implements HttpGetActionInterface
{
    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventRecorder
     */
    private $recorder;

    /**
     * @param RedirectFactory $redirectFactory
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     * @param EventRecorder $recorder
     */
    public function __construct(
        RedirectFactory $redirectFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        EventRecorder $recorder
    ) {
        $this->redirectFactory = $redirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->recorder = $recorder;
    }

    /**
     * Execute cancel handler
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if ($order->getId()) {
                $fromStatus = (string) $order->getStatus();
                $cancelled = false;

                // Cancel the order
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addCommentToStatusHistory(__('Payment cancelled by customer.'));
                    $this->orderRepository->save($order);
                    $cancelled = true;
                }

                $orderRef = (string) $order->getIncrementId();

                $this->recorder->record(
                    Event::of('checkout.return.cancelled', [
                        'order_status' => $fromStatus,
                        'order_updated' => $cancelled
                    ])->about(['order_ref' => $orderRef])
                );

                if ($cancelled) {
                    $this->recorder->record(
                        Event::of('order.marked_failed', [
                            'source' => 'cancel_return',
                            'payment_status' => 'cancelled',
                            'from_status' => $fromStatus,
                            'to_status' => (string) $order->getStatus()
                        ])->about(['order_ref' => $orderRef])
                    );
                }

                // Restore quote
                $this->checkoutSession->restoreQuote();
            }

            $this->messageManager->addWarningMessage(__('Payment was cancelled. Please try again.'));

        } catch (\Exception $e) {
            $this->logger->error('Paypercut cancel error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while cancelling the payment.'));
        }

        $redirect->setPath('checkout/cart');
        return $redirect;
    }
}

