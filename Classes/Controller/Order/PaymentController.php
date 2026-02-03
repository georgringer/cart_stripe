<?php

declare(strict_types=1);
namespace GeorgRinger\CartStripe\Controller\Order;

use Extcode\Cart\Controller\Cart\ActionController;
use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Order\Item;
use Extcode\Cart\Domain\Model\Order\Payment;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Event\Order\FinishEvent;
use Extcode\Cart\Service\SessionHandler;
use Extcode\Cart\Utility\CartUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PaymentController extends ActionController
{
    use LoggerAwareTrait;

    protected ?Cart $cartObject = null;

    /**
     * @var array<mixed>
     */
    protected array $cartConf = [];

    /**
     * @var array<mixed>
     */
    protected $cartStripeConf = [];

    public function __construct(
        protected PersistenceManager $persistenceManager,
        protected SessionHandler $sessionHandler,
        protected CartRepository $cartRepository,
        protected PaymentRepository $paymentRepository,
        CartUtility $cartUtility,
        private readonly ConnectionPool $connectionPool
    ) {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
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
            if ($this->cartObject instanceof Cart) {
                $orderItem = $this->cartObject->getOrderItem();
                if ($orderItem instanceof Item) {
                    /** @var Payment $payment */
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
            }
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartstripe.controller.order.payment.action.success.error_occurred',
                    'CartStripe'
                ) ?? '',
                '',
                ContextualFeedbackSeverity::ERROR
            );
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartstripe.controller.order.payment.action.success.access_denied',
                    'CartStripe'
                ) ?? '',
                '',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->htmlResponse();
    }

    public function cancelAction(): ResponseInterface
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'));

            if ($this->cartObject instanceof Cart) {
                /** @var Item $orderItem */
                $orderItem = $this->cartObject->getOrderItem();
                /** @var Payment $payment */
                $payment = $orderItem->getPayment();

                $payment->setStatus('canceled');

                $this->paymentRepository->update($payment);
                $this->persistenceManager->persistAll();

                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartstripe.controller.order.payment.action.cancel.successfully_canceled',
                        'CartStripe'
                    ) ?? '',
                );

                return $this->redirect('show', 'Cart\Cart', 'Cart');
            }
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartstripe.controller.order.payment.action.cancel.error_occurred',
                    'CartStripe'
                ) ?? '',
                '',
                ContextualFeedbackSeverity::ERROR
            );
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartstripe.controller.order.payment.action.cancel.access_denied',
                    'CartStripe'
                ) ?? '',
                '',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->htmlResponse();
    }

    protected function loadCartByHash(string $hash, string $type = 'SHash'): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cart_domain_model_cart');
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

        $unserializedCart = unserialize($row['serialized_cart'], [Cart\Cart::class]);
        $dataMapper = GeneralUtility::makeInstance(DataMapper::class);
        $items = $dataMapper->map(Cart::class, [$row]);
        /** @var Cart $cartObject */
        $cartObject = $items[0];
        $cartObject->setCart($unserializedCart);
        $this->cart = $unserializedCart;
        $this->cartObject = $cartObject;
    }
}
