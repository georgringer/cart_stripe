<?php

call_user_func(static function () {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
        'cart_stripe',
        'Configuration/TypoScript',
        'Shopping Cart - Stripe'
    );
});
