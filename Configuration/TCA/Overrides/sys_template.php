<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

call_user_func(static function () {
    ExtensionManagementUtility::addStaticFile(
        'cart_stripe',
        'Configuration/TypoScript',
        'Shopping Cart - Stripe'
    );
});
