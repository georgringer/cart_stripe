<?php
declare(strict_types=1);

namespace GeorgRinger\CartStripe\EventListener\Order\Payment;


use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Cart\Cart as CartCart;
use Extcode\Cart\Domain\Model\Cart\CartCoupon;
use Extcode\Cart\Domain\Model\Cart\CartCouponPercentage;
use Extcode\Cart\Domain\Model\Order\Item as OrderItem;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Event\Order\PaymentEvent;
use GeorgRinger\CartStripe\Configuration;
use http\Exception\UnexpectedValueException;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class ProviderRedirect
{

    protected ConfigurationManager $configurationManager;
    protected PersistenceManager $persistenceManager;
    protected TypoScriptService $typoScriptService;
    protected UriBuilder $uriBuilder;
    protected CartRepository $cartRepository;
    protected array $paymentQuery = [];
    protected OrderItem $orderItem;

    protected CartCart $cart;

    protected string $cartSHash = '';
    protected string $cartFHash = '';
    protected array $cartStripeConfiguration = [];
    protected array $cartConf = [];
    protected Configuration $configuration;

    public function __construct(
        ConfigurationManager $configurationManager,
        PersistenceManager $persistenceManager,
        TypoScriptService $typoScriptService,
        UriBuilder $uriBuilder,
        CartRepository $cartRepository
    )
    {
        $this->configurationManager = $configurationManager;
        $this->persistenceManager = $persistenceManager;
        $this->typoScriptService = $typoScriptService;
        $this->uriBuilder = $uriBuilder;
        $this->cartRepository = $cartRepository;

        $this->cartConf = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_FRAMEWORK,
            'Cart'
        );

        $this->cartStripeConfiguration = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_FRAMEWORK,
            'CartStripe'
        );
        $this->configuration = new Configuration();
        $this->loadApi();
    }

    public function __invoke(PaymentEvent $event): void
    {
        $this->orderItem = $event->getOrderItem();
        if ($this->orderItem->getPayment()->getProvider() !== 'STRIPE') {
            return;
        }

        $this->cart = $event->getCart();

        $cart = $this->saveCurrentCartToDatabase();
        $lineItems = [];
        foreach ($cart->getCart()->getProducts() as $product) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => $product->getTitle(),
                    ],
                    'unit_amount' => $product->getPrice() * 100,
                ],
                'quantity' => $product->getQuantity(),
            ];
        }
        $this->cartSHash = $cart->getSHash();
        $this->cartFHash = $cart->getFHash();

        Stripe::setApiKey($this->configuration->getStripeApiKey());
        $billingAddress = $this->orderItem->getBillingAddress();

        $checkout_session = Session::create([
            'line_items' => $lineItems,
            'mode' => 'payment',
            'customer_creation' => 'if_required',
            'customer_email' => $billingAddress ? $billingAddress->getEmail() : '',
            'success_url' => $this->getUrl('success', $this->cartSHash),
            'cancel_url' => $this->getUrl('cancel', $this->cartSHash),
        ]);

        header('HTTP/1.1 303 See Other');
        header('Location: ' . $checkout_session->url);

        $event->setPropagationStopped(true);
    }

    protected function saveCurrentCartToDatabase(): Cart
    {
        $cart = GeneralUtility::makeInstance(Cart::class);

        $cart->setOrderItem($this->orderItem);
        $cart->setCart($this->cart);
        $cart->setPid((int)$this->cartConf['settings']['order']['pid']);

        $this->cartRepository->add($cart);
        $this->persistenceManager->persistAll();

        return $cart;
    }


    protected function getUrl(string $action, string $hash): string
    {
        $pid = (int)$this->cartConf['settings']['cart']['pid'];

        $arguments = [
            'tx_cartstripe_cart' => [
                'controller' => 'Order\Payment',
                'order' => $this->orderItem->getUid(),
                'action' => $action,
                'hash' => $hash,
            ],
        ];

        return $this->uriBuilder->reset()
            ->setTargetPageUid($pid)
            ->setTargetPageType((int)$this->cartStripeConfiguration['redirectTypeNum'])
            ->setCreateAbsoluteUri(true)
            ->setArguments($arguments)
            ->build();
    }

    private function loadApi(): void
    {
        if (class_exists(Session::class)) {
            return;
        }
        $path = $this->configuration->getNonComposerAutoloadPath();
        if (empty($path)) {
            throw new UnexpectedValueException('No path to non composer autoload found', 1627993943);
        }
        if (!is_file($path)) {
            throw new UnexpectedValueException('No file found at path ' . $path, 1627993944);
        }
        require_once $path;
    }
}
