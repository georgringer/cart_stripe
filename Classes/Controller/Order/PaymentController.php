<?php
declare(strict_types=1);

namespace GeorgRinger\CartStripe\Controller\Order;

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Order\Item;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Service\MailHandler;
use Extcode\Cart\Service\SessionHandler;
use Extcode\Cart\Utility\CartUtility;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\LogManagerInterface;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
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
    protected Cart $cart;

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

    protected function initializeAction(): void
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
    }

    public function successAction(): void
    {
//        DebuggerUtility::var_dump(GeneralUtility::_POST());
//        DebuggerUtility::var_dump($this->request->getArguments());
//die('xxx');
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'));

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();
                if ($orderItem) {

                    $payment = $orderItem->getPayment();

                    if ($payment->getStatus() !== 'paid') {
                        $payment->setStatus('paid');
                        $this->paymentRepository->update($payment);
                        $this->persistenceManager->persistAll();


                        $this->notify($orderItem);
                        $this->clearCart($orderItem->getPid());
                    }
                }

                $this->redirect('show', 'Cart\Order', 'Cart', ['orderItem' => $orderItem]);
            } else {
                die('error');
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpaypal.controller.order.payment.action.success.error_occured',
                        'cart_paypal'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            die('zzzz');
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpaypal.controller.order.payment.action.success.access_denied',
                    'cart_paypal'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
    }

    protected function notify(Item $orderItem): void
    {
        $this->sendBuyerMail($orderItem);
        $this->sendSellerMail($orderItem);
    }

    protected function clearCart(int $pid)
    {
        $this->sessionHandler->clear($pid);

        $GLOBALS['TSFE']->fe_user->setKey('ses', 'cart_billing_address_' . $pid, null);
        $GLOBALS['TSFE']->fe_user->setKey('ses', 'cart_shipping_address_' . $pid, null);
        $GLOBALS['TSFE']->fe_user->storeSessionData();
        die('xxclx');
    }

    /**
     * Send a Mail to Buyer
     *
     * @param Item $orderItem
     */
    protected function sendBuyerMail(
        Item $orderItem
    )
    {
        $mailHandler = GeneralUtility::makeInstance(
            MailHandler::class
        );
        $mailHandler->setCart($this->cart->getCart());
        $mailHandler->sendBuyerMail($orderItem);
    }

    /**
     * Send a Mail to Seller
     *
     * @param Item $orderItem
     */
    protected function sendSellerMail(
        Item $orderItem
    )
    {
        $mailHandler = GeneralUtility::makeInstance(
            MailHandler::class
        );
        $mailHandler->setCart($this->cart->getCart());
        $mailHandler->sendSellerMail($orderItem);
    }

    public function cancelAction(): void
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'), 'FHash');

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();
                $payment = $orderItem->getPayment();

                $this->restoreCartSession();

                $payment->setStatus('canceled');

                $this->paymentRepository->update($payment);
                $this->persistenceManager->persistAll();

                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpaypal.controller.order.payment.action.cancel.successfully_canceled',
                        'cart_paypal'
                    )
                );


                $this->redirect('show', 'Cart\Cart', 'Cart');
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpaypal.controller.order.payment.action.cancel.error_occured',
                        'cart_paypal'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpaypal.controller.order.payment.action.cancel.access_denied',
                    'cart_paypal'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
    }


    protected function restoreCartSession(): void
    {
        $cart = $this->cart->getCart();
        $cart->resetOrderNumber();
        $cart->resetInvoiceNumber();
        $this->sessionHandler->write($cart, $this->cartConf['settings']['cart']['pid']);
    }

    protected function loadCartByHash(string $hash, string $type = 'SHash'): void
    {
        $querySettings = GeneralUtility::makeInstance(
            Typo3QuerySettings::class
        );
        $querySettings->setStoragePageIds([$this->cartConf['settings']['order']['pid']]);
        $this->cartRepository->setDefaultQuerySettings($querySettings);

        $findOneByMethod = 'findOneBy' . $type;
        $this->cart = $this->cartRepository->$findOneByMethod($hash);
    }
}
