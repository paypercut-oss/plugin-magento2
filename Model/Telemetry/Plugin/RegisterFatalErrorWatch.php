<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry\Plugin;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Paypercut\Payment\Model\Telemetry\FatalErrorWatch;

/**
 * Arms the fatal-error reporter for the request about to be dispatched.
 *
 * A fatal on the checkout page breaks our payment form whichever module raised
 * it, and it never reaches a catch block, so a debug session sees nothing at
 * all unless a shutdown handler looks.
 */
class RegisterFatalErrorWatch
{
    /**
     * @var FatalErrorWatch
     */
    private $watch;

    /**
     * @param FatalErrorWatch $watch
     */
    public function __construct(FatalErrorWatch $watch)
    {
        $this->watch = $watch;
    }

    /**
     * @param FrontControllerInterface $subject
     * @param RequestInterface $request
     * @return null
     */
    public function beforeDispatch(FrontControllerInterface $subject, RequestInterface $request)
    {
        $this->watch->register();

        return null;
    }
}
