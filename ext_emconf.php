<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Cart - Stripe',
    'description' => 'Shopping Cart(s) for TYPO3 - Stripe Payment Provider',
    'category' => 'services',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'author_company' => 'extco.de UG (haftungsbeschränkt)',
    'state' => 'alpha',
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
            'cart' => '7.4.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
