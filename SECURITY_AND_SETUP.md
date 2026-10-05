# Servora security & Paystack setup

## Important: rotate old credentials
The uploaded project contained hard-coded payment and SMTP credentials. They were removed from this cleaned copy. Rotate/revoke the old Paystack, Flutterwave and SMTP/app-password credentials in their provider dashboards before deploying.

## Required environment values
Copy `.env.example` values into your hosting environment. Do **not** commit a real `.env` file.

Required for Paystack:
- `APP_URL` — public HTTPS base URL in production, e.g. `https://example.com/service-platform`
- `PAYSTACK_SECRET_KEY` — server-side secret key
- `PAYSTACK_PUBLIC_KEY` — public key (kept for future inline checkout; redirect flow currently only needs the secret server-side)

## Paystack dashboard
Set the webhook URL to:
`https://YOUR-DOMAIN/service-platform/payment/paystack_webhook.php`

The browser callback is set automatically during initialization to:
`/client/verify_payment.php`

The Paystack checkout cancel button is set through `metadata.cancel_action` to:
`/client/payment_cancelled.php`

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
- APP_URL (example locally: http://localhost/service-platform)

Flutterwave cancellation is handled before transaction verification because a cancelled redirect may not contain a transaction_id.

## Multi-Provider Data Bundles (CheapDataHub & VTPass)
Servora now supports dual upstream suppliers for data bundle purchases:
- **CheapDataHub** (Provider ID: 1, `cheapdatahub`)
- **VTPass** (Provider ID: 8, `vtpass`)

### Environment Variables:
- `VTPASS_API_KEY` — VTPass API Key
- `VTPASS_SECRET_KEY` — VTPass Secret Key
- `VTPASS_PUBLIC_KEY` — VTPass Public Key (optional)
- `VTPASS_BASE_URL` — Base URL:
  - Live: `https://api-service.vtpass.com/api`
  - Sandbox: `https://sandbox.vtpass.com/api`

### Pricing & Profit Rules:
- **Default Profit Markup**: VTPass plans are initialized with a default profit margin of **₦50.00** per purchase (`Selling Price = Supplier Cost + ₦50.00`).
- **Admin Customization**: Admins can customize the profit margin and selling price dynamically from the Admin Data Plans (`admin/data_plans.php`) and Edit Plan (`admin/edit_data_plan.php`) interfaces with real-time automatic calculation.
- **Client Privacy**: Provider names, supplier costs, and profit margins are strictly hidden from clients. Clients only see the Network, Plan Name, Data Size, Validity, and final Selling Price.
- **Provider Transparency**: Admins can filter data plans and orders by provider (CheapDataHub vs VTPass), with dedicated visual badges.
- **Automatic Sync**: Admins can synchronize all official VTPass variation plans (MTN, Airtel, Glo, 9mobile/T2) anytime via `admin/sync_vtpass_data.php`.

