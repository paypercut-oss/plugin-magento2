# Paypercut Payment Module for Magento 2

A comprehensive payment integration module for Magento 2 that provides card payments, Buy Now Pay Later (BNPL) installments, and subscription management through the Paypercut payment gateway.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture Overview](#architecture-overview)
- [Payment Methods](#payment-methods)
- [Checkout Integrations](#checkout-integrations)
- [Subscription System](#subscription-system)
- [API Integration](#api-integration)
- [Frontend Components](#frontend-components)
- [Troubleshooting](#troubleshooting)
- [Security](#security)

---

## Features

### Card Payments
- Authorize and capture payments
- Partial capture support
- Full and partial refunds
- Void transactions
- Saved cards (Vault) for returning customers

### Buy Now Pay Later (BNPL)
- Installment payment plans (3, 6, or 12 months)
- Configurable order limits (min/max)
- Automatic installment calculation at checkout

### Subscriptions
- Recurring billing for subscription products
- Configurable billing intervals (daily, weekly, monthly, yearly)
- Trial period support
- Customer self-service (pause, cancel, resume)
- Product-level subscription overrides

---

## Requirements

- Magento 2.4.x
- PHP 7.4 or higher
- Paypercut merchant account with API credentials

### Module Dependencies
- Magento_Sales
- Magento_Payment
- Magento_Checkout
- Magento_Catalog
- Magento_Vault
- Magento_Eav

---

## Installation

### Via Composer

```bash
composer require paypercut/magento2
bin/magento module:enable Paypercut_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual Installation

1. Create directory: `app/code/Paypercut/Payment`
2. Copy module files to the directory
3. Run Magento setup commands:

```bash
bin/magento module:enable Paypercut_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Configuration

Navigate to **Stores > Configuration > Sales > Payment Methods** in the Magento Admin.

### Card Payment Settings

| Setting | Description |
|---------|-------------|
| **Enabled** | Enable/disable the payment method |
| **Title** | Payment method title shown at checkout |
| **Description** | Description shown to customers |
| **Environment** | Sandbox or Production |
| **Secret Key** | Your Paypercut secret key |
| **Payment Action** | `authorize_capture` (immediate charge) or `authorize` (manual capture) |
| **New Order Status** | Order status after successful payment |
| **Enable Saved Cards** | Allow customers to save cards for future purchases |
| **Debug Mode** | Enable detailed logging |
| **Sort Order** | Display order at checkout |

### BNPL Settings

| Setting | Description |
|---------|-------------|
| **Enabled** | Enable/disable BNPL payment |
| **Title** | Payment method title (default: "Paypercut - Pay in Installments") |
| **BNPL Secret Key** | Separate secret key for BNPL API |
| **Minimum Order Total** | Minimum cart total for BNPL (default: 100) |
| **Maximum Order Total** | Maximum cart total for BNPL (default: 10,000) |
| **Available Installments** | Comma-separated months (e.g., "3,6,12") |
| **Sort Order** | Display order at checkout |

### Subscription Settings

| Setting | Description |
|---------|-------------|
| **Enabled** | Enable subscription functionality |
| **Subscription Label** | Label shown on subscription products |
| **Default Billing Interval** | day, week, month, or year |
| **Billing Interval Count** | Number of intervals between charges |
| **Billing Day of Month** | Specific day for monthly billing |
| **Collection Method** | `charge_automatically` or `send_invoice` |
| **Enable Trial** | Allow trial periods |
| **Trial Days** | Number of trial days |
| **Missing Payment Behavior** | `cancel` or `keep` subscription on failed payment |
| **Cancel at Period End** | Cancel at billing period end vs immediately |
| **Allow Customer Cancellation** | Let customers cancel subscriptions |
| **Allow Customer Pause** | Let customers pause subscriptions |
| **Renewal Reminder Days** | Days before renewal to send reminder |

### Webhook Configuration

Configure your Paypercut merchant dashboard to send webhooks to:

```
https://your-store.com/paypercut/payment/ipn
```

---

## Architecture Overview

### Directory Structure

```
Paypercut/Payment/
├── Api/                          # Service contracts
│   └── SubscriptionManagementInterface.php
├── Block/                        # Block classes
│   ├── Info.php                  # Payment info block
│   └── BnplInfo.php              # BNPL info block
├── Controller/Payment/           # Frontend controllers
│   ├── Redirect.php              # Gateway redirect
│   ├── Success.php               # Success callback
│   ├── Cancel.php                # Cancel callback
│   └── Ipn.php                   # Webhook handler
├── Gateway/                      # Payment gateway layer
│   ├── Http/Client/              # HTTP clients
│   ├── Request/                  # Request builders
│   ├── Response/                 # Response handlers
│   └── Validator/                # Response validators
├── Model/                        # Business logic
│   ├── PaymentMethod.php         # Card payment method
│   ├── BnplPaymentMethod.php     # BNPL payment method
│   ├── Api/Client.php            # API client
│   ├── Ui/                       # Checkout UI providers
│   └── Subscription/             # Subscription management
├── Observer/                     # Event observers
├── Setup/Patch/Data/             # Data patches
├── etc/                          # Configuration files
│   ├── config.xml                # Default config
│   ├── di.xml                    # Dependency injection
│   ├── system.xml                # Admin configuration
│   └── payment.xml               # Payment definitions
└── view/frontend/                # Frontend assets
    ├── layout/                   # Layout XML
    ├── templates/                # PHTML templates
    └── web/                      # JS, CSS, HTML templates
```

### Payment Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                        CHECKOUT                                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              Customer Selects Payment Method                     │
│         (Card / BNPL / Saved Card)                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              Order Created with pending_payment                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              Redirect Controller                                 │
│              - Creates checkout/BNPL attempt via API            │
│              - Stores transaction data                          │
│              - Redirects to Paypercut gateway                   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              Paypercut Gateway                                   │
│              (Customer completes payment)                        │
└─────────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
         ┌────────┐     ┌─────────┐    ┌──────────┐
         │Success │     │ Cancel  │    │   IPN    │
         │Callback│     │Callback │    │ Webhook  │
         └────────┘     └─────────┘    └──────────┘
              │               │               │
              ▼               ▼               ▼
         Order Success   Restore Cart    Update Order
         Page            Cancel Order    Create Invoice
```

### Gateway Command Pool

The module uses Magento's payment gateway framework with the following commands:

| Command | Description |
|---------|-------------|
| `authorize` | Authorize payment without capture |
| `capture` | Capture authorized payment |
| `void` | Void authorized transaction |
| `refund` | Refund captured transaction |
| `vault_authorize` | Authorize with saved card |
| `vault_capture` | Capture with saved card |

### Key Classes

| Class | Purpose |
|-------|---------|
| `Model\Api\Client` | HTTP client for Paypercut API |
| `Model\PaymentMethod` | Card payment method implementation |
| `Model\BnplPaymentMethod` | BNPL payment method implementation |
| `Model\Subscription\SubscriptionManager` | Subscription lifecycle management |
| `Gateway\Request\AuthorizationRequest` | Builds authorization requests |
| `Gateway\Response\TxnIdHandler` | Handles transaction responses |

---

## Payment Methods

### Card Payment (`paypercut_card`)

Standard card payment with redirect to Paypercut's hosted payment page.

**Capabilities:**
- Authorize
- Capture (full and partial)
- Refund (full and partial)
- Void
- Vault (saved cards)

**Code:**
```php
$payment->getMethod(); // Returns: paypercut_card
```

### BNPL Payment (`paypercut_bnpl`)

Buy Now Pay Later installment payments.

**Capabilities:**
- Authorize
- Capture (full only)
- Refund (full and partial)
- Void

**Order Restrictions:**
- Minimum order total: configurable (default 100)
- Maximum order total: configurable (default 10,000)
- Not available for admin-created orders

**Code:**
```php
$payment->getMethod(); // Returns: paypercut_bnpl
```

### Vault Payment (`paypercut_vault`)

Saved card payments using Magento's Vault framework.

**Features:**
- Tokenized card storage
- Quick checkout for returning customers
- Required for subscription payments

---

## Checkout Integrations

This module supports multiple checkout solutions. Each checkout type requires specific configuration and has different integration mechanisms.

### 1. Standard Luma Checkout

The default Magento checkout uses Knockout.js components.

**Files:**
- `view/frontend/layout/checkout_index_index.xml` - Layout configuration
- `view/frontend/web/js/view/payment/method-renderer/paypercut.js` - Card renderer
- `view/frontend/web/js/view/payment/method-renderer/paypercut-bnpl.js` - BNPL renderer
- `view/frontend/web/template/payment/form.html` - Card form template
- `view/frontend/web/template/payment/bnpl-form.html` - BNPL form template

**Payment Flow:**
1. Customer selects payment method
2. Order is placed via Magento checkout
3. `afterPlaceOrder()` redirects to `paypercut/payment/redirect`
4. Redirect controller creates payment session with Paypercut API
5. Customer is redirected to Paypercut hosted payment page
6. After payment, customer returns to success/cancel URL
7. IPN webhook updates order status

### 2. Hyva Checkout (Standard)

For standard Hyva Checkout (non-React), Alpine.js templates are used.

**Files:**
- `view/frontend/layout/hyva_checkout_components.xml` - Layout configuration
- `view/frontend/templates/checkout/payment/method/paypercut-card.phtml` - Card template
- `view/frontend/templates/checkout/payment/method/paypercut-bnpl.phtml` - BNPL template
- `Block/Checkout/BnplConfig.php` - Block providing BNPL configuration

**BNPL Installments:**
The BNPL template displays installment options calculated server-side by `BnplConfigProvider`. Options are stored in `window.__paypercutBnplConfig` and rendered via Alpine.js.

**Payment Flow:**
Same as Luma checkout - redirect to `paypercut/payment/redirect` after order placement.

### 3. Zento Checkout (ReactCheckout) - REQUIRED FOR THIS MODULE

**IMPORTANT:** Zento Checkout is a separate React-based checkout solution located at:
```
app/code/Hyva/ReactCheckout
```

This Paypercut module is designed to work with Zento Checkout when the payment methods need to be displayed on the checkout page. Zento Checkout uses GraphQL and React components for payment handling.

#### How Zento Checkout Integration Works

**Payment Flow (different from Luma/Hyva):**
1. Customer selects payment method in React checkout
2. Order is placed via GraphQL mutation
3. `fetchPaymentForm` GraphQL query is called with order ID
4. `Zento\Payment\Model\Resolver\PaymentForm` resolves the query
5. Resolver calls `Paypercut\Payment\Model\Form::getForm()` to get redirect URL
6. React component redirects to Paypercut using `window.location.replace()`
7. Customer completes payment on Paypercut
8. IPN webhook updates order status

**Key Files in Zento Checkout:**
```
Hyva/ReactCheckout/
├── reactapp/src/paymentMethods/paymentForm/
│   ├── renderers.js                    # Maps payment codes to components
│   ├── src/components/
│   │   ├── PaymentForm.jsx             # Main payment form component
│   │   └── BnplInstallmentsForm.jsx    # BNPL installment selector
│   └── src/api/fetchPaymentForm/
│       ├── query.js                    # GraphQL query
│       └── fetchPaymentForm.js         # API call
```

**Key Files in Zento Payment Module:**
```
Zento/Payment/
├── etc/schema.graphqls                 # GraphQL schema for paymentForm query
└── Model/Resolver/PaymentForm.php      # GraphQL resolver
```

#### Required Configuration in Zento Payment

The `Zento\Payment\Model\Resolver\PaymentForm` must include Paypercut payment methods. Verify these cases exist in `getPaymentObject()`:

```php
case 'paypercut_bnpl':
    if ($this->moduleManager->isEnabled('Paypercut_Payment')) {
        if ($this->scopeConfig->getValue('payment/paypercut_bnpl/active', ScopeInterface::SCOPE_STORE, $storeId)) {
            $object = $objectManager->create(\Paypercut\Payment\Model\Form::class);
        }
    }
    break;
case 'paypercut_card':
    if ($this->moduleManager->isEnabled('Paypercut_Payment')) {
        if ($this->scopeConfig->getValue('payment/paypercut_card/active', ScopeInterface::SCOPE_STORE, $storeId)) {
            $object = $objectManager->create(\Paypercut\Payment\Model\Form::class);
        }
    }
    break;
case 'paypercut_vault':
    if ($this->moduleManager->isEnabled('Paypercut_Payment')) {
        if ($this->scopeConfig->getValue('payment/paypercut_vault/active', ScopeInterface::SCOPE_STORE, $storeId)) {
            $object = $objectManager->create(\Paypercut\Payment\Model\Form::class);
        }
    }
    break;
```

#### BNPL Installments in Zento Checkout

In Zento Checkout, installment options come from `window.checkoutConfig.payment.paypercut_bnpl.installmentOptions`, which is populated by `Paypercut\Payment\Model\Ui\BnplConfigProvider::getConfig()`.

The React component `BnplInstallmentsForm.jsx` displays the installment options when BNPL is selected.

**Troubleshooting Installments Not Showing:**
1. Verify BNPL is enabled in admin
2. Check cart total is within min/max limits
3. Ensure `BnplConfigProvider` is registered in `di.xml`
4. Check browser console for `window.checkoutConfig.payment.paypercut_bnpl`

#### Redirection Not Working in Zento Checkout

If redirection to Paypercut is not working:

1. **Verify GraphQL resolver**: Ensure `paypercut_card`, `paypercut_bnpl`, and `paypercut_vault` cases exist in `Zento\Payment\Model\Resolver\PaymentForm::getPaymentObject()`

2. **Check Form model**: The `Paypercut\Payment\Model\Form::getForm()` method must return valid JSON with an `action` key:
   ```json
   {"action": "https://checkout.paypercut.io/...", "data": [], "method": "GET"}
   ```

3. **Verify payment renderers**: In `ReactCheckout/reactapp/src/paymentMethods/paymentForm/renderers.js`:
   ```javascript
   export default {
     paypercut_card: PaymentForm,
     paypercut_vault: PaymentForm,
     paypercut_bnpl: PaymentForm,
   };
   ```

4. **Check PaymentForm.jsx**: Verify the payment action is registered:
   ```javascript
   registerPaymentAction('paypercut_card', paymentSubmitHandler);
   registerPaymentAction('paypercut_vault', paymentSubmitHandler);
   registerPaymentAction('paypercut_bnpl', paymentSubmitHandler);
   ```

---

## Subscription System

### Enabling Subscriptions for Products

1. Navigate to **Catalog > Products**
2. Edit the product
3. Set the following attributes:

| Attribute | Description |
|-----------|-------------|
| `paypercut_subscription_enabled` | Enable subscription for this product |
| `paypercut_subscription_interval` | Override billing interval |
| `paypercut_subscription_interval_count` | Override interval count |
| `paypercut_subscription_price` | Override subscription price |
| `paypercut_subscription_cycles` | Maximum billing cycles (0 = unlimited) |

### Subscription Lifecycle

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Active    │────▶│   Paused    │────▶│   Active    │
└─────────────┘     └─────────────┘     └─────────────┘
       │                                       │
       │                                       │
       ▼                                       ▼
┌─────────────┐                        ┌─────────────┐
│  Cancelled  │                        │  Cancelled  │
└─────────────┘                        └─────────────┘
```

### Programmatic Subscription Management

```php
use Paypercut\Payment\Api\SubscriptionManagementInterface;

// Inject via constructor
public function __construct(
    SubscriptionManagementInterface $subscriptionManager
) {
    $this->subscriptionManager = $subscriptionManager;
}

// Get customer subscriptions
$subscriptions = $this->subscriptionManager->getCustomerSubscriptions($customerId);

// Cancel subscription
$this->subscriptionManager->cancelSubscription($subscriptionId);

// Pause subscription
$this->subscriptionManager->pauseSubscription($subscriptionId);

// Resume subscription
$this->subscriptionManager->resumeSubscription($subscriptionId);
```

---

## API Integration

### API Endpoints

**Standard Payment API:**
- Base URL (Production): `https://api.paypercut.io/v1`
- Base URL (Sandbox): `https://sandbox-api.paypercut.io/v1`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/checkouts` | POST | Create checkout session |
| `/checkouts/{id}` | GET | Get checkout status |
| `/transactions/authorize` | POST | Authorize transaction |
| `/transactions/capture` | POST | Capture transaction |
| `/transactions/void` | POST | Void transaction |
| `/transactions/refund` | POST | Refund transaction |

**BNPL API:**
- Base URL (Production): `https://api.paypercut.io/bnpl/v1`
- Base URL (Sandbox): `https://sandbox-api.paypercut.io/bnpl/v1`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/orders` | POST | Create BNPL order |
| `/orders/capture` | POST | Capture BNPL order |
| `/orders/cancel` | POST | Cancel BNPL order |
| `/orders/refund` | POST | Refund BNPL order |

**Subscription API:**
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/subscriptions` | POST | Create subscription |
| `/subscriptions/{id}` | GET | Get subscription |
| `/subscriptions/{id}` | PATCH | Update subscription |
| `/subscriptions/{id}/cancel` | POST | Cancel subscription |
| `/subscriptions/{id}/pause` | POST | Pause subscription |
| `/subscriptions/{id}/resume` | POST | Resume subscription |

### Authentication

All API requests use Bearer token authentication:

```
Authorization: Bearer {api_key}
```

---

## Frontend Components

### Checkout Integration

The module supports:
- Standard Magento Luma checkout
- Hyva Checkout (via `hyva_checkout_components.xml`)

### JavaScript Components

| Component | File | Purpose |
|-----------|------|---------|
| Card Payment | `view/frontend/web/js/view/payment/method-renderer/paypercut.js` | Card payment form |
| BNPL Payment | `view/frontend/web/js/view/payment/method-renderer/paypercut-bnpl.js` | BNPL with installment selector |
| Vault | `view/frontend/web/js/view/payment/method-renderer/vault.js` | Saved card selector |

### Templates

| Template | Purpose |
|----------|---------|
| `form.html` | Card payment checkout form |
| `bnpl-form.html` | BNPL checkout form with installment options |
| `vault.html` | Saved cards display |

---

## Troubleshooting

### Enable Debug Mode

1. Go to **Stores > Configuration > Sales > Payment Methods > Paypercut Card**
2. Set **Debug Mode** to **Yes**
3. Check logs in `var/log/paypercut.log`

### Common Issues

**Payment method not showing at checkout:**
- Verify the payment method is enabled
- Check country restrictions
- For BNPL: verify cart total is within min/max limits

**API connection errors:**
- Verify API credentials are correct
- Check environment setting (Sandbox vs Production)
- Ensure server can reach Paypercut API endpoints

**Subscription not created:**
- Verify subscriptions are enabled globally
- Check product has subscription enabled
- Ensure customer saved their card (Vault required)

**Webhook not processing:**
- Verify webhook URL is correctly configured in Paypercut dashboard
- Check for firewall blocking incoming requests
- Review `var/log/paypercut.log` for errors

### Hyva/Zento Checkout Specific Issues

**BNPL installments not showing:**

1. **Empty installment options array:**
   - The `BnplConfigProvider` calculates options based on `CheckoutSession::getQuote()->getGrandTotal()`
   - If the session is not properly initialized, grand total may be 0
   - Check browser console: `console.log(window.checkoutConfig?.payment?.paypercut_bnpl)`
   - For Hyva: also check `console.log(window.__paypercutBnplConfig)`

2. **Cart total outside min/max range:**
   - Verify admin settings for min/max order totals
   - `BnplConfigProvider::isAvailableForAmount()` returns false if outside range

3. **Config provider not registered:**
   - Ensure `BnplConfigProvider` is in `etc/frontend/di.xml` as a config provider

**Redirect not working after order placement:**

1. **Luma Checkout:**
   - Check `afterPlaceOrder()` in `paypercut-bnpl.js` or `paypercut.js`
   - Should call `window.location.replace(url.build('paypercut/payment/redirect'))`

2. **Zento Checkout (ReactCheckout):**
   - Verify `PaymentForm.jsx` has the payment action registered
   - Check `fetchPaymentForm` GraphQL query returns valid data
   - Verify `Zento\Payment\Model\Resolver\PaymentForm` has the payment method case
   - Check `Paypercut\Payment\Model\Form::getForm()` returns valid redirect URL

3. **Standard Hyva Checkout:**
   - Hyva Checkout handles redirects differently based on the checkout implementation
   - May require Magewire component or custom Alpine.js integration

**GraphQL errors in Zento Checkout:**

Check the GraphQL response for `paymentForm` query:
```graphql
query {
  paymentForm(order_id: "000000001", success: "https://...", cancel: "https://...") {
    order_id
    form_data
  }
}
```

If `form_data` is empty or error, check:
- `Zento\Payment\Model\Resolver\PaymentForm` has Paypercut cases
- `Paypercut\Payment\Model\Form` is creating valid API requests
- API credentials are correct

### Log Files

| Log | Content |
|-----|---------|
| `var/log/paypercut.log` | Payment-specific debug logs |
| `var/log/system.log` | General Magento errors |
| `var/log/exception.log` | PHP exceptions |
| `var/log/graphql/` | GraphQL debug logs (if enabled) |

### Debug Commands

Check if module is enabled:
```bash
bin/magento module:status Paypercut_Payment
```

Check payment method configuration:
```bash
bin/magento config:show payment/paypercut_card/active
bin/magento config:show payment/paypercut_bnpl/active
```

Clear caches after configuration changes:
```bash
bin/magento cache:flush
bin/magento setup:di:compile
```

---

## Security

### Payment Integration Method

The Paypercut module uses a **redirect-based (hosted payment page)** integration for all payment methods -- Card, BNPL, and Vault. When a customer proceeds to pay, they are redirected from the Magento storefront to Paypercut's secure hosted payment page, where all sensitive payment data is collected and processed. Upon completion, the customer is returned to the merchant's Magento site via a callback URL.

At no point does the Magento server receive, handle, or have access to raw cardholder data such as card numbers, CVV/CVC codes, expiration dates, or cardholder names.

### PCI Compliance

Because payment data is entered exclusively on Paypercut's PCI-certified hosted payment page and never passes through the merchant's Magento environment, this integration qualifies for **PCI SAQ A** -- the simplest Self-Assessment Questionnaire level. SAQ A applies to merchants that have fully outsourced all cardholder data processing to a PCI DSS-validated third-party provider and do not electronically store, process, or transmit any cardholder data on their own systems.

### Data Handling Summary

| Concern | Status |
|---------|--------|
| **Customer payment data entered on Magento** | No. All payment data is entered on Paypercut's hosted page. |
| **Sensitive payment data stored on Magento** | No. No card numbers, CVV codes, expiration dates, or cardholder names are stored. |
| **Data stored on Magento** | Transaction reference IDs, payment intent IDs, and opaque payment method tokens only -- used for order management, refunds, and subscription lifecycle. |
| **Saved cards (Vault)** | Stored as tokenized references (public hashes) via the Magento Vault framework. No actual card data is retained. |

### Webhook Security

Inbound IPN (Instant Payment Notification) webhooks from Paypercut are validated using HMAC-SHA256 signature verification against a shared webhook secret configured in the Magento admin. This includes timestamp tolerance checks to prevent replay attacks. Merchants should ensure the webhook secret is configured to enable this protection.

---

## Integration Checklist

Before going live, verify:

- [ ] API credentials configured (sandbox first, then production)
- [ ] Webhook URL configured in Paypercut dashboard
- [ ] Payment methods enabled in admin
- [ ] BNPL min/max order totals configured
- [ ] For Zento Checkout: `PaymentForm` GraphQL resolver has Paypercut cases
- [ ] For Zento Checkout: `renderers.js` includes Paypercut payment methods
- [ ] IPN endpoint accessible from Paypercut servers
- [ ] SSL certificate valid on success/cancel URLs
- [ ] Tested full payment flow in sandbox environment

---

## Support

For technical support or feature requests, contact your Paypercut account representative or visit the Paypercut merchant dashboard.

---

## License

Commercial License - All Rights Reserved
