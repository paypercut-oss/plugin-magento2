# plugin-magento2

Paypercut's **Magento 2 payment module** (PHP). Distributed via Composer as `paypercut/module-payment` (Magento module name `Paypercut_Payment`). Lets Magento 2.4.x merchants accept **cards, BNPL, and tokenized (vault) payments**, with subscription support layered on top. An older `magento2` repo lives under the `paypercut` org and is the legacy predecessor.

**Audience**: Magento 2 merchants, agencies, and store integrators wiring Paypercut as a payment gateway.

## Layout

Standard Magento 2 module under the `Paypercut/Payment` vendor/module pair. Installed via Composer or manually into `app/code/Paypercut/Payment`. No `upload/` wrapper — the repo root **is** the module root (`registration.php`, `etc/`, `Controller/`, `Model/`, etc., directly at the top).

## Requirements

- Magento `framework` **>= 103.0.0 < 104.0.0** (Magento 2.4.x).
- PHP **>= 8.4** (per `composer.json`; the README mentions 7.4+ — trust `composer.json`).

## Architecture

Three distinct payment methods, all defined in `etc/payment.xml` and wired via `etc/config.xml` + `etc/di.xml`:

| Method code | Class | Purpose |
|---|---|---|
| `paypercut_card` | `Paypercut\Payment\Model\PaymentMethod` | Standard card / wallet checkout via Paypercut Hosted Checkout |
| `paypercut_bnpl` | `Paypercut\Payment\Model\BnplPaymentMethod` | Buy Now Pay Later via Paypercut's BNPL gateway |
| `paypercut_vault` | (via `Model\Ui\ConfigProvider::VAULT_CODE`) | Tokenized re-use of saved cards through Magento Vault |

Directory layout:

- `registration.php`, `composer.json` — module registration + Composer metadata.
- `etc/` — `payment.xml`, `config.xml`, `di.xml`, `module.xml`, `routes.xml`, `events.xml`, `crontab.xml`, `adminhtml/system.xml`.
- `Controller/Payment/` — `Redirect.php`, `Success.php`, `Cancel.php`, `Ipn.php` (webhook), `BnplCallback.php`.
- `Model/` — `PaymentMethod.php`, `BnplPaymentMethod.php`, `Form.php`, `PaypercutOrderHelper.php`, `Api/Client.php` (~600 lines), `Subscription/SubscriptionManager.php`, Adminhtml source models.
- `Gateway/` — `Http/Client/{TransactionClient,BnplClient}`, request builders, response handlers, validators (Magento's payment-gateway abstraction).
- `Block/`, `View/frontend/`, `View/base/`, `View/adminhtml/` — rendering blocks + templates (frontend checkout, base order info, admin order payment info).
- `Setup/Patch/Data/AddSubscriptionProductAttribute.php` — adds the EAV attribute used by subscription products.
- `Observer/` — `CreateSubscriptionAfterOrderPlace`, `CreateRefundAfterCreditMemo`.
- `Cron/BnplStatusCheck.php` — polls BNPL attempt status every 5 minutes.
- `Api/SubscriptionManagementInterface.php` — public interface for subscription management.
- `i18n/ro_RO.csv` — translations (Romanian only at the moment).

## Payment flow

- **Card** (redirect): customer → checkout → redirect to Paypercut Hosted Checkout → payment processed off-site → IPN webhook updates the order → customer returns to the success page.
- **BNPL** (redirect + poll): redirect to Paypercut BNPL UI → callback URL nudges Magento to check status → final status arrives via `Cron/BnplStatusCheck.php` (every 5 minutes).
- **Vault**: customer pays once, opts to save card → token stored in Magento Vault → reusable for subsequent orders and for subscriptions.

## API integration

- **Card / standard**: `https://api.paypercut.io/v1` (production AND sandbox map to the same URL in code; the `environment` toggle exists in admin but doesn't switch host — only key selection).
- **BNPL**: `https://api.paypercut.io/bnpl/v1` (production), `https://bnpl-gw.bender.paypercut.net/v1` (sandbox).
- API client: `Model/Api/Client.php` — `createCheckout`, `getCheckout`, `createBnplAttempt`, `getBnplAttempt`, `getBnplAttemptStatus`, refund + subscription + customer methods.
- Secrets stored encrypted (Magento's `Magento\Config\Model\Config\Backend\Encrypted`) at `payment/paypercut_card/secret_key`, `payment/paypercut_bnpl/secret_key`, `payment/paypercut_card/webhook_secret`.

## Webhook (IPN)

- Endpoint: `/paypercut/payment/ipn` (route declared in `etc/routes.xml`; controller `Controller/Payment/Ipn.php`).
- Signature header: `X-Paypercut-Signature: t=<timestamp>,v1=<hex_signature>`.
- Signed payload: `"<timestamp>.<raw_body>"`, HMAC-SHA256 using `webhook_secret`.
- **Timestamp tolerance: 5 minutes** — out-of-sync server clocks will reject webhooks.
- Idempotency: event IDs are tracked; refund events also de-dup by `refund_id`.
- Events handled: `checkout_session.completed`, `payment_intent.captured` / `.succeeded`, `payment_intent.authorized`, `payment_intent.payment_failed`, `refund.created` / `.succeeded`.

## Configuration

Admin: **Stores → Configuration → Sales → Payment Methods → Paypercut**. Sections:

- **General** — enable, title, description, sort order.
- **API Credentials** — environment (sandbox/prod), secret key, webhook secret, debug mode.
- **Order Settings** — new-order status, payment action (`authorize` vs `authorize_capture`), refund action (`none` or `credit_memo_on_refund`).
- **Vault** — enable/disable saved cards.
- **Country Settings** — restrict to specific countries.
- **BNPL** — min/max order totals, installment options (3/6/12 months), preview toggle.
- **Subscription** — billing interval, trial days, renewal reminders, auto-cancel, customer pause/cancel permissions.

## Database

No custom tables. Module data lives in **standard Magento tables**, especially `sales_order_payment.additional_information`:

- `paypercut_payment_intent`, `paypercut_payment_method_id`, `paypercut_payment_id`, `paypercut_subscription_ids`, `paypercut_id` (BNPL attempt).

One data patch: `Setup/Patch/Data/AddSubscriptionProductAttribute.php` adds an EAV attribute on products to flag subscription items. No schema migrations.

## Common commands

```bash
# Install (Composer — preferred)
composer require paypercut/module-payment
bin/magento module:enable Paypercut_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile          # required after enabling
bin/magento setup:static-content:deploy
bin/magento cache:flush

# Verify install
bin/magento module:status Paypercut_Payment

# Package a release zip (mirrors .github/workflows/release-zip.yml on a v* tag)
git tag v1.1.1 && git push --tags     # syncs composer.json version, builds .zip artifact
```

No PHPUnit / Codeception suite is wired up — `composer.json` has no `phpunit` dep or `test` script.

## Conventions

- **Magento payment-gateway abstraction**: command pool (`authorize`, `capture`, `void`, `refund`, `vault_authorize`, `vault_capture`) defined in `etc/di.xml` via `VirtualType`. Changes here require `setup:di:compile`.
- **Encrypted secrets**: API key + webhook secret use Magento's `Encrypted` backend model. Never store them as plain `text`.
- **Module dependencies** (strict sequence in `etc/module.xml`): `Magento_Sales`, `Magento_Payment`, `Magento_Checkout`, `Magento_Catalog`, `Magento_Vault`, `Magento_Eav`.
- **BNPL is polled**, not pushed — relies on Magento cron firing the 5-minute job. If cron is dead, BNPL orders stick in pending.
- **Subscription attribute** is added via a data patch (not `db_schema.xml`); only applies to products.

## Gotchas

- **PCI scope**: SAQ A — all cardholder data lives on Paypercut's servers; Magento never touches PANs.
- **`setup:di:compile` required**: this module uses heavy `di.xml` VirtualTypes. Skipping `di:compile` after enabling / upgrading silently breaks gateway commands. In production mode it's mandatory.
- **Webhook clock skew**: 5-minute window is strictly enforced. Verify server time (NTP) before debugging "valid signature" failures.
- **BNPL relies on cron**: `Cron/BnplStatusCheck.php` runs every 5 minutes. If Magento cron isn't running, BNPL orders never advance past pending.
- **Subscriptions can double-create**: both the `checkout_submit_all_after` observer AND the IPN webhook handler can create subscriptions. If both fire (rare, but possible on retries), you can get duplicates. Audit `paypercut_subscription_ids` on incidents.
- **API URL "environment" toggle is partial**: the card/standard API URL is the same in code for sandbox and production — the toggle really controls *which key set* to use, not which host. BNPL does switch hosts.
- **Vault tokens** are serialized JSON in `payment.additional_information`, not in a custom vault table. Don't try to query them with SQL — go through Magento's vault API.
- **Refund action modes**: `"none"` (manual credit memo) vs `"credit_memo_on_refund"` (auto-create on webhook). Switching mid-flight can produce missing or duplicate credit memos.
- **i18n is sparse**: only `ro_RO.csv` is committed. English strings live in the source; add new locales as `i18n/<locale>.csv` Magento-style.
- **composer.lock is excluded from the release zip** — merchants pull dependencies at install time. Don't pin transitive deps in `composer.json` without intent.
- **Payment handoff with paycore**: checkout, status, refunds all flow through `api.paypercut.io` (paycore) or the BNPL gateway. Non-2xx responses are user-visible — surface them via Magento's logger.
