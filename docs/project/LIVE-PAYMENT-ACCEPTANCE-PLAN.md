# Live Payment Acceptance Plan

**Authorization required before any live transaction:**

```text
OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES
```

Until then: prepare only. No live monetary transaction.

## Environment prerequisites

- Isolated staging WooCommerce site
- HTTPS
- Current certified WP/WC/PHP (see Compatibility matrix)
- Dedicated test merchant/account if provider supports
- Minimal-value approved live transaction amount
- No real customer data
- Synthetic product/order
- Logging redacted (no PAN/CVV/secrets)
- Rollback/cleanup procedure

## Mandatory live one-time scenarios

Credit Card / KNET where account supports:

1. successful initial payment
2. explicit cancel
3. decline
4. 3DS/authentication failure if applicable
5. browser closes before return
6. webhook before browser return
7. browser return before webhook
8. duplicate webhook
9. duplicate browser return
10. status inquiry after successful capture
11. status inquiry after failure/cancel
12. network loss after provider dispatch

## Required proof per scenario

- order never false-paid
- verified capture only from authenticated provider status
- transaction/payment IDs bound correctly
- amount/currency/reference/order identity exact
- duplicate/reordered signals do not double-complete
- no secrets/tokens in logs
- user-facing outcome correct

**Scope:** one-time payments only. No automatic recurring debit under this authorization.

**Classification until executed:** `EXTERNAL REQUIRED — OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES`

## Executable runbook

This runbook turns the scenario list above into the exact sequence the operator follows. Nothing here may be executed before `OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES` is recorded, and this authorization never covers automatic recurring debit.

Running it is also blocked while `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=UNRESOLVED`: a live result obtained under an unconfirmed authentication contract cannot be used as release evidence, because a later provider answer can invalidate what the run appeared to prove.

### Step 0 — record the run

Open an evidence file at `docs/project/evidence/LIVE-ONE-TIME-ACCEPTANCE-<YYYY-MM-DD>.md` before the first transaction and record: exact plugin package SHA-256 and file count, WordPress, WooCommerce and PHP versions, storage mode (legacy or HPOS), site URL, operator, start time in UTC and the authorization token. A run whose package identity is not recorded first is not acceptance evidence.

### Step 1 — prepare the environment

1. Staging site over HTTPS, isolated from production customer data.
2. Install the exact canonical package built by `scripts/build-release.sh`, verified by `scripts/verify-release.sh`. Never install from a working tree.
3. Live merchant credentials entered through the gateway settings screen only. Never in a file, a screenshot or this repository.
4. One synthetic product at the minimum approved amount, and a synthetic customer account.
5. Order and gateway logging on, with redaction confirmed on a sandbox order first.

### Step 2 — per scenario

Run the twelve scenarios in the order listed above, one at a time, and for each one record:

1. order ID, provider track ID and provider payment/transaction ID;
2. the order's status and paid state after the browser return;
3. the order's status and paid state after the webhook, when the scenario has one;
4. the amount and currency on the order against the amount and currency the provider reports;
5. the order notes the run produced, verbatim;
6. the outcome the customer saw.

A scenario passes only when the order's paid state came from an authenticated provider status bound to that order and that transaction, the amount and currency match exactly, and repeated or reordered signals left the order completed once. Anything else is a failure, including a correct-looking result reached by a path the plugin did not authenticate.

Stop the run on the first failure. A failed scenario is a bug report against this repository: reproduce it in a test, fix it on a bounded branch and re-run the whole sequence on the rebuilt package, rather than re-testing the one scenario.

### Step 3 — cleanup

1. Refund or cancel every live transaction through the provider's own merchant dashboard, and record each reversal reference. The plugin does not perform automatic refunds.
2. Remove the synthetic product and orders from the staging store.
3. Rotate the live merchant credentials used for the run if they were entered anywhere outside the gateway settings screen.
4. Confirm no log, screenshot or evidence file in the run captured a PAN, CVV, bearer token, API secret or customer token.

### Step 4 — record the result

Complete the evidence file with the end time, a per-scenario pass or fail table and the reversal references, then update `RELEASE-CANDIDATE-GATE.md`. `- [ ] live one-time payment acceptance complete` may only be checked when all twelve scenarios passed on one package identity, in one run, with cleanup complete.
