<?php

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'CartStripe',
    'Cart',
    [
        \GeorgRinger\CartStripe\Controller\Order\PaymentController::class => 'success, cancel, notify',
    ],
    [
        \GeorgRinger\CartStripe\Controller\Order\PaymentController::class => 'success, cancel, notify',
    ]
);
