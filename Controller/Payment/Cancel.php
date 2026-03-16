<?php
namespace Paypercut\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Message\ManagerInterface;
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
     * @param RedirectFactory $redirectFactory
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        RedirectFactory $redirectFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->redirectFactory = $redirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
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
                // Cancel the order
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addCommentToStatusHistory(__('Payment cancelled by customer.'));
                    $this->orderRepository->save($order);
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

