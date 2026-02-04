<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Cart - Stripe',
    'description' => 'Shopping Cart(s) for TYPO3 - Stripe Payment Provider',
    'category' => 'services',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'alpha',
    'version' => '0.0.3',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
            'cart' => '9.0.0-11.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
