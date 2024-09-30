<?php
declare(strict_types=1);
namespace GeorgRinger\CartStripe\EventListener\Order\Payment;

use Extcode\Cart\Event\Order\EventInterface;
use Extcode\Cart\EventListener\Order\Finish\ClearCart as FinishClearCart;
use Extcode\Cart\Service\SessionHandler;
use Extcode\Cart\Utility\CartUtility;

class ClearCart extends FinishClearCart
{

    public function __invoke(EventInterface $event): void
    {
        $orderItem = $event->getOrderItem();

        $provider = $orderItem->getPayment()->getProvider();
//die('clear');
        if (strpos($provider, 'STRIPE') === 0) {
            parent::__invoke($event);
        }
    }
}
