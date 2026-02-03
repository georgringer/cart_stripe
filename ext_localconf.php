<?php

use GeorgRinger\CartStripe\Controller\Order\PaymentController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

ExtensionUtility::configurePlugin(
    'CartStripe',
    'Cart',
    [
        PaymentController::class => 'success, cancel, notify',
    ],
    [
        PaymentController::class => 'success, cancel, notify',
    ]
);
