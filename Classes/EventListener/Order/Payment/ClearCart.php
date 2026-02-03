<?php

declare(strict_types=1);
namespace GeorgRinger\CartStripe\EventListener\Order\Payment;

use Extcode\Cart\Event\Order\EventInterface;
use Extcode\Cart\EventListener\Order\Finish\ClearCart as FinishClearCart;

class ClearCart extends FinishClearCart
{
    public function __invoke(EventInterface $event): void
    {
        $orderItem = $event->getOrderItem();

        $provider = $orderItem->getPayment()?->getProvider();

        if (str_starts_with((string)$provider, 'STRIPE')) {
            parent::__invoke($event);
        }
    }
}
