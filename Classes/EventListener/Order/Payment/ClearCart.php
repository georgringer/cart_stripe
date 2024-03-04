<?php
declare(strict_types=1);
namespace GeorgRinger\CartStripe\EventListener\Order\Payment;

use Extcode\Cart\Event\Order\EventInterface;
use Extcode\Cart\EventListener\Order\Finish\ClearCart as FinishClearCart;
use Extcode\Cart\Service\SessionHandler;
use Extcode\Cart\Utility\CartUtility;
use Extcode\Cart\Utility\ParserUtility;

class ClearCart extends FinishClearCart
{
    public function __construct(
        CartUtility $cartUtility,
        ParserUtility $parserUtility,
        SessionHandler $sessionHandler
    ) {
        $this->cartUtility = $cartUtility;
        $this->parserUtility = $parserUtility;
        $this->sessionHandler = $sessionHandler;
    }

    public function __invoke(EventInterface $event): void
    {
        $orderItem = $event->getOrderItem();

        $provider = $orderItem->getPayment()->getProvider();

        if (strpos($provider, 'PAYPAL') === 0) {
            parent::__invoke($event);
        }
    }
}
