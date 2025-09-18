# TYPO3 Extension `cart_stripe`

This extension provides a stripe payment provider for the `cart` extension.

This extension is currently more a proof of concept and not ready for production!

## Usage

- Install the extension
- Provide the stripe API key (best is to set it in ENV variables)
- Configure shipping handling: Enable "Handle shipping fee as shipping option" to use Stripe shipping options (without VAT). By default (disabled), shipping costs are treated as regular line items (with VAT) to match EXT:cart calculations.
- Copy the TypoScript of the extension + Adopt the payment per country options

## Todo

- Testing
- Cancel payment
