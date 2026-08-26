# Debug sessions (client telemetry)

A merchant-started, time-boxed diagnostic feed. Off until someone presses
**Start debug session** in *Stores → Configuration → Sales → Payment Methods →
Paypercut Payment → Debug Session*; ends by itself after about an hour.

Nothing is sent when no session is running. `EventRecorder::record()` reads one
already-cached config value and returns, so call sites on the checkout path are
free to report unconditionally.

## Shape

```
Start  →  POST {api}/v1/telemetry/tokens   (the store's Secret Key, once)
          →  short-lived RS256 token
Events →  POST {edge}/v1/telemetry         (the token; never the Secret Key)
```

Both hosts are derived from the single `payment/paypercut_card/environment`
value, in the same call sequence, before any network call. The edge verifies the
token offline against Ory's JWKS, pinned to one issuer and one audience per
environment, so **a token minted for one environment is rejected by every other
environment's edge with a 401 that looks exactly like a forged token**. That is
why the two hosts may never be resolved from independent settings, and why an
unknown environment yields no session rather than a confusing one.

| Environment | Payment API | Telemetry edge |
|---|---|---|
| `production` | `https://api.paypercut.io/` | `https://telemetry.paypercut.io/` |
| `stage` | `https://api.stage.paypercut.net/` | `https://telemetry.stage.paypercut.net/` |
| `dev` | `https://api.dev.paypercut.net/` | `https://telemetry.dev.paypercut.net/` |
| `sandbox` (legacy) | resolves to `production` | resolves to `production` |
| anything else | falls back to production | **none — no session** |

Every base is accepted only if it is `https` on a `paypercut.net` or
`paypercut.io` host: a credential travels on the mint request.

| Piece | Role |
|---|---|
| `Event` | Named constructors, the privacy boundary, the wire envelope |
| `EventRecorder` | Buffers events; one queue write per request, at shutdown |
| `EventQueue` | Capped persistent buffer; deny assertion; batch splitting |
| `Store` | The three storage primitives: config row, `flag` rows, database locks |
| `TelemetrySession` | Session record, token custody, locks, teardown, constants |
| `TokenMinter` / `MintErrorMapper` | API key → token, and what a rejection means |
| `EdgeClient` / `Flusher` | POST one batch; decide and settle one delivery attempt |
| `SentLog` | Local copy of delivered envelopes for the merchant to read |
| `FatalErrorWatch` | Shutdown handler for fatals that reach no catch block |
| `DebugSessionManager` | Start / stop / status, behind three admin controllers |

Storage on this platform:

| Need | Where |
|---|---|
| session record (read on every request) | `core_config_data` at `payment/paypercut_card/telemetry_session`, deliberately absent from `system.xml` so the settings form cannot author it |
| runtime counters, queue, inflight batch, sent log | the `flag` table via `FlagManager`, with an `expires_at` inside the value — they must survive a cache flush |
| start / flush locks | `LockManagerInterface::lock($name, 0)` — a real database lock, so exactly one concurrent caller wins |
| audit log | `Psr\Log\LoggerInterface`, which is not gated on the module's **Debug Mode** preference |

## Events

### Checkout

| Event | When |
|---|---|
| `checkout.hosted.redirected` | Shopper sent to the hosted page (`source`, `method`, `order_status`) |
| `checkout.hosted.create_failed` | Session or BNPL attempt creation threw |
| `checkout.hosted.redirect_missing` | The request succeeded and the response carried no URL |
| `checkout.order_missing` | The redirect ran for an order the checkout session could not load |
| `checkout.return.pending` | The shopper is back but nothing has confirmed the payment yet |
| `checkout.return.unverifiable` | The return could not be checked (`no_order_context`, `lookup_failed`) |
| `checkout.return.cancelled` | The shopper came back through the cancel URL |

`source` distinguishes the two hosted-checkout entry points this module has:
`redirect_controller` (Luma and Hyvä) and `payment_form` (the Zento/React
`fetchPaymentForm` resolver). `method` is `paypercut_card` or `paypercut_bnpl`.

### Payment and order

| Event | When |
|---|---|
| `payment.succeeded` | Paypercut reported paid, with whether the order moved |
| `payment.failed` | Paypercut reported a failure, or BNPL declined/errored |
| `payment.status_unverifiable` | The BNPL poller could not read a purchase back (`no_attempt_id`, `lookup_failed`) |
| `order.marked_paid` / `order.marked_failed` | An order status actually changed |
| `order.confirmation_skipped` | A guard declined to confirm, with which one |
| `order.confirmation_refused` | Magento refused to invoice or save a confirmed payment |

### Refunds

| Event | When |
|---|---|
| `refund.succeeded` | Refund accepted (`is_partial`, `has_reason`, `has_refund_id`) |
| `refund.rejected` | The refund never left (`missing_payment_intent`, `invalid_amount`, `order_not_refundable`) |
| `refund.failed` | The API refused it, or the credit memo could not be written |

`has_reason` is a boolean. The reason text a merchant types is theirs and is on
the "not shared" list.

### Webhooks

| Event | When |
|---|---|
| `webhook.received` | A delivery arrived, with its `type` |
| `webhook.rejected` | Signature refused: `empty_body`, `missing_signature`, `invalid_signature_format`, `invalid_signature_structure`, `timestamp_out_of_tolerance` (with `skew_seconds`), `invalid_signature` |
| `webhook.order_updated` | An order was updated from a delivery |
| `webhook.unresolved` | No order matched, with which identifiers the payload carried |
| `webhook.skipped` | A delivery this store deliberately did nothing with, with the reason |
| `webhook.error` | The handler threw |
| `webhook.payload_invalid` | The body was empty, unparsable, or missing an identifier |

`webhook.rejected` is the single most useful event a debug session carries: a
merchant whose orders never leave *Pending Payment* is almost always looking at
one of these — a rotated webhook secret, a clock out of tolerance, or a
signature that never matched. None of it is visible from Paypercut's side. When
no webhook secret is configured at all, this module accepts the delivery
unverified and reports `webhook.skipped` with reason
`webhook_secret_not_configured` instead.

### Vault and subscriptions

| Event | When |
|---|---|
| `payment_method.added` | A payment method was resolved from the checkout session |
| `payment_method.already_saved` | The order already carried one |
| `payment_method.add_failed` | `no_session_id`, `lookup_failed`, `token_not_saved`, `no_payment_method` |
| `subscription.created` | Subscriptions were created for an order, with how many |
| `subscription.create_failed` | Subscription creation threw |

### API

| Event | When |
|---|---|
| `api.request_failed` | A structured 4xx/5xx, an unparsable error body, or a transport failure (`connect` / `transport`), always with `api_context` and `duration_ms` |
| `api.request_slow` | A call that succeeded but took 3s or more |
| `api.response_unparsable` | A 2xx body that was not JSON — byte count only, never the body |

`api_context` is the caller's fixed phrase (`create_checkout`, `create_refund`,
`get_bnpl_attempt_status`, …), never the path: a path carries ids and the
merchant host in its query string.

### Lifecycle and environment

| Event | When |
|---|---|
| `session.started` / `session.stopped` | Lifecycle |
| `environment.snapshot` | Module, Magento, PHP and theme versions; multi-store and TLS flags |
| `environment.configuration` | How the payment methods are configured; re-sent when the payment settings are saved mid-session |
| `environment.plugins` | Enabled third-party modules and versions, chunked |
| `php.fatal` | A fatal that ended the request |

`environment.plugins` excludes Magento's own `Magento_*` modules: a stock
install has several hundred of them, they are implied by `magento_version`, and
sending them would crowd the queue with the one thing support never needs to
compare between stores.

Every failure carries `origin` (`paypercut` / `plugin` / `theme` / `core`) and,
where applicable, `origin_plugin` — the module name or Composer package from the
first stack frame outside our own directory. That is the answer to "which module
broke us". The wire values are identical across all five Paypercut plugins so
support can compare stores; only merchant-facing copy says "module".

## What never goes on the wire

Card data (screened with a Luhn check on every value), credentials of any kind,
refund reason text, customer names, email addresses, billing and shipping
addresses, order totals, line items, absolute filesystem paths, the admin user
id of whoever started the session, and upstream API prose.

That last one is the rule most easily lost in a port. **No exception message
travels**, from any throwable: the Paypercut API quotes submitted input back, and
Magento is worse — its DB layer puts the full SQL and `user@host` in the message,
and its `LocalizedException`s on the refund and invoice paths carry money
amounts the disclosure promises are not shared. `error.code`, `error.type`,
`origin` and the scrubbed stack carry the diagnosis; `api_code`, `api_param` and
`trace_id` carry it for an API failure. A message this module authored, set with
`->because()`, is the diagnosis and stays. A fatal from an uncaught throwable is
reported as its class name only, for the same reason.

The deny assertion in `EventQueue::append()` is the last gate every producer
funnels through, and it drops the **whole event**, not the offending field: a
field that trips it means the event was assembled wrongly and the rest of it
cannot be trusted either. It screens the **whole envelope** — the correlation
fields `order_ref` / `payment_id` / `payment_intent_id` included, because on the
webhook paths their value came from an unauthenticated request body, not from
this store. `EnvelopeScreenTest` enumerates the envelope rather than naming
fields, so a field added to `Event::envelope()` is screened by construction.

The credential list the assertion compares against is read at the **default
scope and at every website scope**: the credential fields are website-scoped, and
a list holding the wrong website's key leaves the literal-secret comparison dead
for that website. A module NAME can also trip the denied-key pattern all by
itself (`ParadoxLabs_Authnetcim`, `MSP_TwoFactorAuth`); those entries travel as
`module_<n>` values in their own `environment.plugins` chunk so one such module
cannot cost the whole inventory.

The merchant-facing promise lives in two places that must stay in step —
`view/adminhtml/templates/system/config/debug-session-disclosure.phtml` and the
**Debug sessions (client telemetry)** section of `README.md`. `DisclosureTest`
fails if they drift.

## Structural blind spots

1. **A store that has never been connected cannot start a session** — the token
   is minted from the store's Secret Key, so first-time onboarding failures are
   invisible by construction.
2. **A credential or environment change ends the session mid-request**
   (`connection_changed`), so anything after that point in that request is not
   recorded.
3. **A card refused on Paypercut's hosted page is not visible here.** This module
   is redirect-only; the shopper is off the store while paying. Closing that gap
   needs browser-side telemetry.
4. **Deliveries are not deduplicated**, so `webhook.received` carries no
   `duplicate` flag the way the WooCommerce plugin's does.

## Running the suite

The unit suite covers the pure parts — the deny assertion, the environment
pairing, the batch splitter, the flusher's decision table, the mint clock
arithmetic, and the two drift guards.

```bash
php phpunit-10.phar        # or: vendor/bin/phpunit
```

`Test/bootstrap.php` deliberately avoids Composer: installing this module's
dependencies needs authenticated access to `repo.magento.com`, and nothing in
the suite needs a Magento application.

## Turning the panel off

Remove the `Paypercut_Payment::telemetry` ACL resource from the admin role. Stop
and Status stay reachable for a role that holds it, so a session that is already
running can always be ended.
