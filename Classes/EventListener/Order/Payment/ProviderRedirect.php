<?php

declare(strict_types=1);
namespace GeorgRinger\CartStripe\EventListener\Order\Payment;

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Cart\Cart as CartCart;
use Extcode\Cart\Domain\Model\Order\Item as OrderItem;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Event\Order\PaymentEvent;
use GeorgRinger\CartStripe\Configuration;
use http\Exception\UnexpectedValueException;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\TaxRate;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

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
    ) {
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
        if ($this->orderItem->getPayment()?->getProvider() !== 'STRIPE') {
            return;
        }

        $this->cart = $event->getCart();
        $cart = $this->saveCurrentCartToDatabase();
        Stripe::setApiKey($this->configuration->getStripeApiKey());

        $lineItems = [];
        foreach ($cart->getCart()?->getProducts() as $product) {
            $lineItem = [
                'price_data' => [
                    'currency' => strtolower($cart->getCart()?->getCurrencyCode()),
                    'product_data' => [
                        'name' => $product->getTitle(),
                    ],
                    'unit_amount' => $product->getGross() * 100 / $product->getQuantity(),
                ],
                'quantity' => $product->getQuantity(),
            ];

            // Add tax_rates if product has tax
            if ($product->getTax() > 0 && $product->getTaxClass()) {
                $taxPercentage = (float)$product->getTaxClass()->getCalc() * 100;
                if ($taxPercentage > 0) {
                    $lineItem['tax_rates'] = [
                        $this->getOrCreateTaxRateId($taxPercentage),
                    ];
                }
            }

            $lineItems[] = $lineItem;
        }

        $payment = $this->cart->getPayment();
        $payment_amount = (int)($payment->getGross() * 100 ?: $payment->getConfig()['extra'] * 100 ?: 0);
        if ($payment && $payment_amount) {
            $lineItem = [
                'price_data' => [
                    'currency' => strtolower($cart->getCart()->getCurrencyCode()),
                    'product_data' => [
                        'name' => $payment->getName() ?: $payment->getConfig()['title'] ?: '',
                    ],
                    'unit_amount' => $payment_amount,
                ],
                'quantity' => 1,
            ];
            // Add tax_rates if payment has tax
            if ($payment->getTax() > 0 && $payment->getTaxClass()) {
                $taxPercentage = (float)$payment->getTaxClass()->getCalc() * 100;
                if ($taxPercentage > 0) {
                    $lineItem['tax_rates'] = [
                        $this->getOrCreateTaxRateId($taxPercentage),
                    ];
                }
            }
            $lineItems[] = $lineItem;
        }

        $shipping = $this->cart->getShipping();
        if ($shipping) {
            if ($this->configuration->isHandleShippingAsShippingOption()) {
                // Handle as Stripe shipping option (without VAT)
                $configuration['shipping_options'] = [];
                $configuration['shipping_options'][] = [
                    'shipping_rate_data' => [
                        'type' => 'fixed_amount',
                        'fixed_amount' => [
                            'amount' => (int)($shipping->getGross() * 100 ?: $shipping->getConfig()['extra'] * 100 ?: 0),
                            'currency' => strtolower($cart->getCart()->getCurrencyCode()),
                        ],
                        'display_name' => $shipping->getName() ?: $shipping->getConfig()['title'] ?: '',
                        'tax_behavior' => 'inclusive',
                        'tax_code' => 'txcd_92010001',
                    ],
                ];
            } else {
                // Handle as line item (with VAT) - default behavior
                $shipping_amount = (int)($shipping->getGross() * 100 ?: $shipping->getConfig()['extra'] * 100 ?: 0);
                if ($shipping_amount > 0) {
                    $shippingLineItem = [
                        'price_data' => [
                            'currency' => strtolower($cart->getCart()->getCurrencyCode()),
                            'product_data' => [
                                'name' => $shipping->getName() ?: $shipping->getConfig()['title'] ?: 'Shipping',
                            ],
                            'unit_amount' => $shipping_amount,
                        ],
                        'quantity' => 1,
                    ];

                    // Add tax_rates if shipping has tax
                    if ($shipping->getTax() > 0 && $shipping->getTaxClass()) {
                        $taxPercentage = (float)$shipping->getTaxClass()->getCalc() * 100;
                        if ($taxPercentage > 0) {
                            $shippingLineItem['tax_rates'] = [
                                $this->getOrCreateTaxRateId($taxPercentage),
                            ];
                        }
                    }

                    $lineItems[] = $shippingLineItem;
                }
            }
        }

        $this->cartSHash = $cart->getSHash();
        $this->cartFHash = $cart->getFHash();

        $billingAddress = $this->orderItem->getBillingAddress();

        $configuration = [
            'line_items' => $lineItems,
            'mode' => 'payment',
            'customer_creation' => 'if_required',
            'customer_email' => $billingAddress ? $billingAddress->getEmail() : '',
            'success_url' => $this->getUrl('success', $this->cartSHash),
            'cancel_url' => $this->getUrl('cancel', $this->cartSHash),
            'client_reference_id' => $cart->getOrderItem()->getOrderNumber(),
            // Meta data for the session
            'metadata' => [
                'orderNumber' => $cart->getOrderItem()->getOrderNumber(),
            ],

            // Information for the Payment Intent
            'payment_intent_data' => [
                'metadata' => [
                    'orderNumber' => $cart->getOrderItem()->getOrderNumber(),
                ],
                'description' => LocalizationUtility::translate('LLL:EXT:cart/Resources/Private/Language/locallang.xlf:tx_cart_domain_model_order_item.order_number', 'cart') . ' #' . $cart->getOrderItem()->getOrderNumber(),
            ],
        ];

        if ($this->cart->getCoupons()) {
            $coupon = \Stripe\Coupon::create([
                'amount_off' => abs($this->cart->getDiscountGross()) * 100,
                'currency' => strtolower($cart->getCart()->getCurrencyCode()),
                'duration' => 'once',
                'name' => implode(' / ', array_map(fn ($coupon) => $coupon->getTitle(), $this->cart->getCoupons())),
            ]);
            $configuration['discounts'] = [
                ['coupon' => $coupon->id],
            ];
        }

        $checkout_session = Session::create($configuration);

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

    /**
     * Get or create a Stripe tax rate for the given percentage
     */
    private function getOrCreateTaxRateId(float $percentage): string
    {
        // Try to find existing tax rate first
        $existingRates = TaxRate::all([
            'limit' => 100,
        ]);

        foreach ($existingRates->data as $rate) {
            if ($rate->percentage === $percentage) {
                return $rate->id;
            }
        }

        // Create new tax rate if none found
        // use vat translation string but strip a possible '(%s %%)' from the string, because stripe will show it as well
        $displayName = LocalizationUtility::translate('LLL:EXT:cart/Resources/Private/Language/locallang.xlf:tx_cart.tax_vat.value', 'cart');
        if ($displayName && strpos($displayName, '(') !== false) {
            $displayName = trim(substr($displayName, 0, strpos($displayName, '(')));
        }

        $taxRate = TaxRate::create([
            'display_name' => $displayName ?: 'VAT',
            'percentage' => $percentage,
            'inclusive' => true,
        ]);

        return $taxRate->id;
    }
}
