# Chimpstorep - Mailchimp & Hikashop E-commerce Integration

Synchronize HikaShop customers and orders with a Mailchimp E-commerce Store, allowing purchases made through your Joomla website to be recorded in Mailchimp.

## Features

- Automatic customer synchronization with Mailchimp when a HikaShop order is created.
- Automatic creation or updating of Mailchimp customer records.
- Synchronization of customer email addresses, first names, and last names.
- Automatic order synchronization, including order ID, customer ID, currency, order total, and product line items.
- Support for product IDs, variant IDs, quantities, and prices.
- Optional Mailchimp campaign attribution using the `mc_cid` URL parameter or cookie.
- Customer marketing opt-in status is explicitly set to `false` when creating a new Mailchimp e-commerce customer.
- Debug logging through HikaShop's logging system.
- Detailed Mailchimp API error reporting, including field-specific validation errors.
- Configurable Mailchimp API key and E-commerce Store ID.

## Requirements

- Joomla 5.x or later
- HikaShop
- PHP 8.1.0 or later
- Mailchimp account with E-commerce API access
- Mailchimp E-commerce Store ID
- Mailchimp API key

## Installation

1. Download the `plg_hikashop_chimpstore.zip` file.
2. In Joomla, navigate to **System → Extensions → Install**.
3. Enable **HikaShop - Mailchimp E-commerce Integration**.
4. Open the plugin settings.
5. Enter your Mailchimp API key and E-commerce Store ID.
6. Enable Debug logging if you want to inspect synchronization activity.

## Configuration notes

- Customer and order synchronization takes place through HikaShop's `onAfterOrderCreate` event.
- Customers are identified in Mailchimp using their HikaShop user ID.
- Customer records are synchronized before the corresponding order is submitted.
- The customer API request uses the `PUT` method to create or update the Mailchimp e-commerce customer.
- First and last names are obtained from the customer's HikaShop billing address. Empty names are omitted to avoid overwriting existing Mailchimp customer information with blank values.
- The `opt_in_status` field is explicitly set to `false` when creating or updating a customer. This does not subscribe customers to marketing emails.
- Orders are submitted using the `POST` method and reference the associated Mailchimp customer by ID.
- Order totals are calculated from the HikaShop order total, discount, and shipping amounts.
- Order currency is retrieved from HikaShop, with USD used as a fallback.
- Product information includes product IDs, variant IDs, quantities, and unit prices.
- When an `mc_cid` campaign identifier is available in the URL or cookie, it is included with the order for campaign attribution.
- If customer synchronization fails, the order synchronization is skipped to avoid creating an order without a successfully synchronized customer.
- API communication uses Mailchimp API v3 with HTTP authentication and JSON requests.
- API errors are logged with their HTTP status, error description, and available field-specific validation details.
- Debug logging is controlled through the plugin's Debug configuration option.

## Privacy and marketing consent

- Customer synchronization is intended for e-commerce recordkeeping.
- The `opt_in_status` value of `false` indicates that the customer has not opted in to marketing emails through this integration.
- Synchronizing an order does not constitute consent to receive marketing communications.
- Newsletter subscriptions and marketing consent should be managed separately through an appropriate subscription workflow.
