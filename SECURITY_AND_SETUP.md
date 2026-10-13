# Subnext security & Paystack setup

## Important: rotate old credentials
The uploaded project contained hard-coded payment and SMTP credentials. They were removed from this cleaned copy. Rotate/revoke the old Paystack, Flutterwave and SMTP/app-password credentials in their provider dashboards before deploying.

## Required environment values
Copy `.env.example` values into your hosting environment. Do **not** commit a real `.env` file.

Required for Paystack:
- `APP_URL` — public HTTPS base URL in production: `https://subnext.com.ng`
- `PAYSTACK_SECRET_KEY` — server-side secret key
- `PAYSTACK_PUBLIC_KEY` — public key (kept for future inline checkout; redirect flow currently only needs the secret server-side)

## Paystack dashboard
Set the webhook URL to:
`https://subnext.com.ng/payment/paystack_webhook.php`

The browser callback is set automatically during initialization to:
`https://subnext.com.ng/client/verify_payment.php`

The Paystack checkout cancel button is set through `metadata.cancel_action` to:
`https://subnext.com.ng/client/payment_cancelled.php?gateway=paystack&reference=...`

A cancel redirect never credits a wallet. Successful wallet credit requires a server-side Paystack verification or a signed `charge.success` webhook.

## Local testing
`localhost` can use the browser callback/cancel redirect, but Paystack cannot send a webhook to localhost. Use a public HTTPS staging URL when testing webhooks.

## Security changes in this build
- Removed hard-coded Paystack, Flutterwave and SMTP secrets.
- Added environment-based DB/payment/mail configuration.
- Added baseline security headers and stronger session settings.
- Added/fixed CSRF protection on high-risk forms that were missing it.
- Added reference ownership, currency, amount and email validation to Paystack verification.
- Kept wallet credit idempotent with row locks and transaction status checks.
- Added webhook HMAC signature verification and NGN validation.
- Disabled the public first-admin bootstrap unless `ALLOW_ADMIN_BOOTSTRAP=1`.
- Blocked directory listing and script execution in upload directories (Apache `.htaccess`).
- Removed legacy Flutterwave payment files and the public DB test endpoint.

## Performance note
Many pages still use Tailwind's browser CDN compiler. It is convenient for local development but should be replaced with a compiled/minified Tailwind CSS file before production. This environment did not bundle a Node/Tailwind build tool into the PHP project, so the UI was left intact rather than risking broken styling.

## Dual payment gateways
The wallet funding page now lets the client choose Paystack or Flutterwave.

Server environment values:
- PAYSTACK_SECRET_KEY
- PAYSTACK_PUBLIC_KEY (optional for current server-side redirect flow)
- FLW_SECRET_KEY
- FLW_PUBLIC_KEY
- FLW_SECRET_HASH (for a Flutterwave webhook when configured)
- APP_URL (e.g. https://subnext.com.ng)

Flutterwave cancellation is handled before transaction verification because a cancelled redirect may not contain a transaction_id.

## CheapDataHub Complete VTU Integration & VTpass Removal
Subnext uses **CheapDataHub** (`https://www.cheapdatahub.ng`) as its primary upstream provider for all VTU services:
- **Airtime Recharge**: MTN (1), Glo (2), Airtel (3), and 9mobile/T2 (4).
- **Data Subscription**: Comprehensive suite of all 49 data plans across MTN, Airtel, and Glo.
- **Cable TV**: DStv, GOtv, and StarTimes bouquets with instant smartcard verification.
- **Electricity**: All 10 supported Nigerian Discos (AEDC, EKEDC, IBEDC, IKEDC, KAEDCO, PHED, JEDPLC, EEDC, YEDC, BEDC) with live meter validation.
- **Exam PINs**: WAEC, NECO, and NABTEB result checker PIN generation.

### Environment Variables:
- `CHEAPDATAHUB_API_KEY` — CheapDataHub Reseller API Bearer Token
- `CHEAPDATAHUB_BASE_URL` — Base URL: `https://www.cheapdatahub.ng/api/v1/resellers`

### Pricing & Profit Rules:
- **Supplier Price Sync**: Admins can synchronize live supplier prices directly from CheapDataHub via `admin/sync_cheapdatahub_plans.php`.
- **Default Profit Markup**: Plans are synced with configurable default markups and supplier base costs.
- **Admin Customization**: Admins can adjust profit margins and selling prices dynamically from `admin/data_plans.php` and `admin/edit_data_plan.php`.
- **Client Privacy**: Supplier costs, supplier names, and profit margins are strictly hidden from clients.
- **VTpass Removal**: All VTpass endpoints, credentials, and dependencies have been cleanly retired. Historical orders and ledger records are fully preserved.

