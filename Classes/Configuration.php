<?php
declare(strict_types=1);

namespace GeorgRinger\CartStripe;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Configuration {

    protected int $type = 2278101;
    protected string $stripeApiKey = '';

    public function __construct() {

        try {
            $configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('cart_stripe');
            $this->stripeApiKey = $configuration['stripeApiKey'] ?? '';
        } catch (\Exception $e) {
        }
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getStripeApiKey(): string
    {
        return $this->stripeApiKey;
    }


}