<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Cart - Stripe',
    'description' => 'Shopping Cart(s) for TYPO3 - Stripe Payment Provider',
    'category' => 'services',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'alpha',
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'cart' => '9.0.0-9.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
