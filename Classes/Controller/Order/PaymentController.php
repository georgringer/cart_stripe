<?php
declare(strict_types=1);

namespace GeorgRinger\CartStripe\Controller\Order;

use Extcode\Cart\Controller\Cart\ActionController;
use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Order\BillingAddress;
use Extcode\Cart\Domain\Model\Order\Item;
use Extcode\Cart\Domain\Model\Order\ShippingAddress;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Event\Order\FinishEvent;
use Extcode\Cart\Service\SessionHandler;
use Extcode\Cart\Utility\CartUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\LogManagerInterface;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Backend;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PaymentController extends ActionController
{
    use LoggerAwareTrait;

    protected PersistenceManager $persistenceManager;
    protected SessionHandler $sessionHandler;
    protected CartRepository $cartRepository;
    protected PaymentRepository $paymentRepository;

    /** @var Cart */
    protected $cartObject;

    protected array $cartConf = [];

    /**
     * @var string|bool
     */
    protected $curlResult;

    /**
     * @var array
     */
    protected $curlResults;

    /**
     * @var array
     */
    protected $cartStripeConf = [];

    public function __construct(
        LogManagerInterface $logManager,
        PersistenceManager $persistenceManager,
        SessionHandler $sessionHandler,
        CartRepository $cartRepository,
        PaymentRepository $paymentRepository,
        CartUtility $cartUtility
    )
    {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $this->persistenceManager = $persistenceManager;
        $this->sessionHandler = $sessionHandler;
        $this->cartRepository = $cartRepository;
        $this->paymentRepository = $paymentRepository;
        $this->cartUtility = $cartUtility;
    }

    public function initializeAction(): void
    {
        $this->cartConf =
            $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'Cart'
            );

        $this->cartStripeConf =
            $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'CartStripe'
            );
        parent::initializeAction();
    }

    public function successAction(): ResponseInterface
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'));
            if ($this->cartObject) {
                $orderItem = $this->cartObject->getOrderItem();
                if ($orderItem) {

                    $payment = $orderItem->getPayment();
                    if ($payment->getStatus() !== 'paid') {
                        $payment->setStatus('paid');
                        $this->paymentRepository->update($payment);
                        $this->persistenceManager->persistAll();

                        $finishEvent = new FinishEvent($this->cart, $orderItem, $this->cartConf);
                        $this->eventDispatcher->dispatch($finishEvent);
                        $this->sessionHandler->writeCart(
                            $this->cartConf['settings']['cart']['pid'],
                            $this->cartUtility->getNewCart($this->cartConf)
                        );
                    }
                }

                return $this->redirect('show', 'Cart\Order', 'Cart', ['orderItem' => $orderItem]);
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartstripe.controller.order.payment.action.success.error_occurred',
                        'cart_stripe'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartstripe.controller.order.payment.action.success.access_denied',
                    'cart_stripe'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
        return $this->htmlResponse();
    }

    public function cancelAction(): ResponseInterface
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'), 'FHash');

            if ($this->cart) {
//                return $this->htmlResponse('cancellednow');
                return $this->redirect('show', 'Cart\Cart', 'Cart');

                $orderItem = $this->cartCart->getOrderItem();
                $payment = $orderItem->getPayment();

                $this->restoreCartSession();

                $payment->setStatus('canceled');

                $this->paymentRepository->update($payment);
                $this->persistenceManager->persistAll();

                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartstripe.controller.order.payment.action.cancel.successfully_canceled',
                        'cart_stripe'
                    )
                );


                return $this->redirect('show', 'Cart\Cart', 'Cart');
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartstripe.controller.order.payment.action.cancel.error_occurred',
                        'cart_stripe'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartstripe.controller.order.payment.action.cancel.access_denied',
                    'cart_stripe'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
        return $this->htmlResponse();
    }


    protected function restoreCartSession(): void
    {
        $cart = $this->cart->getCart();
        $cart->resetOrderNumber();
        $cart->resetInvoiceNumber();
        $cartPid = $this->cartConf['settings']['cart']['pid'];

        $this->sessionHandler->clearCart($cartPid);
        $this->sessionHandler->writeAddress(
            'billing_address_' . $cartPid,
            GeneralUtility::makeInstance(BillingAddress::class)
        );
        $this->sessionHandler->writeAddress(
            'shipping_address_' . $cartPid,
            GeneralUtility::makeInstance(ShippingAddress::class)
        );
    }

    protected function loadCartByHash(string $hash, string $type = 'SHash'): void
    {
//        $querySettings = GeneralUtility::makeInstance(
//            Typo3QuerySettings::class
//        );
//        $querySettings->setStoragePageIds([4,$this->cartConf['settings']['order']['pid']]);
//        $this->cartRepository->setDefaultQuerySettings($querySettings);
//
//        $findOneByMethod = 'findOneBy' . $type;
//        $cart = $this->cartRepository->findByUid(1);
////        $cart = $this->cartRepository->$findOneByMethod($hash);
//        DebuggerUtility::var_dump($cart);die;
//        if ($cart) {
//            $this->cart = $cart;
//        }
//        var_dump($hash);die;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_cart_domain_model_cart');
        $row = $queryBuilder
            ->select('*')
            ->from('tx_cart_domain_model_cart')
            ->where(
                $queryBuilder->expr()->eq('s_hash', $queryBuilder->createNamedParameter($hash))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            // todo exception
            return;
        }

        $unserializedCart = unserialize($row['serialized_cart']);
//        DebuggerUtility::var_dump($unserializedCart);Die;
        $dataMapper = GeneralUtility::makeInstance(DataMapper::class);
        $items = $dataMapper->map(Cart::class, [$row]);
        /** @var Cart $cartObject */
        $cartObject = $items[0];
        $cartObject->setCart($unserializedCart);
//DebuggerUtility::var_dump($cartObject, '$cartObject');
        $this->cart = $unserializedCart;
        $this->cartObject = $cartObject;
//        $this->initializeAction();
    }
}
