# Servora API Providers & Developer Integration Guide

This document records the official API providers, endpoints, authentication methods, environment configuration, and live activation instructions for all 7 digital services on the Servora platform.

---

## Architecture Summary

Servora uses organized backend provider adapters located in `includes/`:
- `includes/CheapDataHubClient.php` & `includes/DataProviderProcessor.php` (Data bundles)
- `includes/FiveSimProvider.php` & `includes/VirtualNumberProvider.php` (Foreign virtual numbers)
- `includes/UtilityProvider.php` & `includes/UtilityOrderProcessor.php` (Airtime, Electricity, Cable TV, Bulk SMS, Exam PINs)

All API keys are held strictly server-side and read via environment variables. **No API credentials are ever leaked to client browsers.**

When live API keys are left blank in `.env`, the system operates in **safe sandbox simulation mode**, allowing full UI testing, wallet debiting/refunds, transaction tracking, and admin controls without failing.

---

## 1. Data Subscription (Active & Live)

- **SERVICE**: Nigerian Mobile Data Bundles (MTN, Airtel, Glo, 9mobile)
- **PROVIDER**: CheapDataHub
- **STATUS**: Integrated, Live & Active
- **OFFICIAL WEBSITE**: [https://www.cheapdatahub.ng/](https://www.cheapdatahub.ng/)
- **REGISTRATION URL**: [https://www.cheapdatahub.ng/register](https://www.cheapdatahub.ng/register)
- **API DOCUMENTATION**: [https://www.cheapdatahub.ng/api-docs](https://www.cheapdatahub.ng/api-docs)
- **BASE URL**: `https://www.cheapdatahub.ng/api/v1/resellers`
- **ENV VARIABLES**:
  ```text
  CHEAPDATAHUB_BASE_URL=https://www.cheapdatahub.ng/api/v1/resellers
  CHEAPDATAHUB_API_KEY=your_live_token_here
  ```
- **AUTHENTICATION**: Bearer Token in `Authorization` header (`Authorization: Bearer <API_KEY>`)
- **SUPPORTED PRODUCTS**: SME, Gifting, Corporate Gifting for MTN, Airtel, Glo, 9mobile.
- **IMPLEMENTED ENDPOINTS**:
  - Check Balance: `GET /user/balance/`
  - Fetch Plans: `GET /data/plans/`
  - Purchase Data: `POST /data/purchase/`
- **WEBHOOK / RECONCILIATION**: Handled via `includes/DataOrderReconciliationService.php` with automated wallet refunds on provider failure.
- **LIVE ACTIVATION STEPS**:
  1. Register at `https://www.cheapdatahub.ng/register`.
  2. Fund your CheapDataHub reseller wallet via bank transfer/Paystack.
  3. Navigate to API / Developer Settings and copy your API Token.
  4. Paste into `CHEAPDATAHUB_API_KEY=` inside `.env`.

---

## 2. Foreign Virtual Phone Numbers (Active & Live)

- **SERVICE**: Temporary SMS & OTP Verification Numbers (WhatsApp, Telegram, Facebook, etc.)
- **PROVIDER**: 5SIM
- **STATUS**: Integrated & Active (with sandbox fallback)
- **OFFICIAL WEBSITE**: [https://5sim.net/](https://5sim.net/)
- **REGISTRATION URL**: [https://5sim.net/register](https://5sim.net/register)
- **API DOCUMENTATION**: [https://5sim.net/docs](https://5sim.net/docs)
- **BASE URL**: `https://5sim.net/v1`
- **ENV VARIABLES**:
  ```text
  FIVESIM_BASE_URL=https://5sim.net/v1
  FIVESIM_API_KEY=your_5sim_token_here
  ```
- **AUTHENTICATION**: Bearer Token in `Authorization` header (`Authorization: Bearer <API_KEY>`)
- **SUPPORTED PRODUCTS**: 12+ major global services (WhatsApp, Telegram, Facebook, Instagram, Google, TikTok, X, OpenAI/ChatGPT, Apple, Microsoft, Amazon, Netflix, Steam, Discord, Snapchat) across 20+ countries (USA, UK, Canada, Netherlands, Germany, France, Nigeria, etc.) with official retina flags and brand logos.
- **IMPLEMENTED ENDPOINTS**:
  - User Balance: `GET /v1/user/check`
  - Order Activation Number: `GET /v1/user/buy/activation/{country}/{operator}/{product}`
  - Check SMS Code: `GET /v1/user/check/{id}`
  - Finish / Complete Order: `GET /v1/user/finish/{id}`
  - Cancel & Refund Order: `GET /v1/user/cancel/{id}`
- **LIVE ACTIVATION STEPS**:
  1. Register at `https://5sim.net/register`.
  2. Top up 5SIM balance via card, crypto, or local voucher.
  3. Go to Profile -> API Settings and copy your API Key.
  4. Set `FIVESIM_API_KEY=` in `.env`.

---

## 3. Airtime Top-Up (VTU)

- **SERVICE**: Instant Airtime Recharge for Nigerian Telecom Networks
- **PROVIDER**: CheapDataHub (Primary) / VTpass (Alternative)
- **STATUS**: Integrated in `UtilityProvider.php` (Live CheapDataHub endpoint ready)
- **OFFICIAL WEBSITE**: [https://www.cheapdatahub.ng/](https://www.cheapdatahub.ng/) / [https://www.vtpass.com/](https://www.vtpass.com/)
- **REGISTRATION URL**: [https://www.cheapdatahub.ng/register](https://www.cheapdatahub.ng/register)
- **API DOCUMENTATION**: [https://www.cheapdatahub.ng/api-docs](https://www.cheapdatahub.ng/api-docs)
- **BASE URL**: `https://www.cheapdatahub.ng/api/v1/resellers`
- **ENV VARIABLES**:
  ```text
  AIRTIME_PROVIDER_API_KEY=
  AIRTIME_PROVIDER_BASE_URL=https://www.cheapdatahub.ng/api/v1/resellers
  ```
- **AUTHENTICATION**: Bearer Token (`Authorization: Bearer <API_KEY>`)
- **SUPPORTED PRODUCTS**: MTN (ID: 1), Airtel (ID: 2), Glo (ID: 3), 9mobile (ID: 4)
- **IMPLEMENTED ENDPOINTS**:
  - Purchase Airtime: `POST /airtime/purchase/`
    - Payload: `{"network_id": 1, "phone_number": "08012345678", "amount": 500}`
- **LIVE ACTIVATION STEPS**:
  - Same as CheapDataHub. If `CHEAPDATAHUB_API_KEY` is set in `.env`, Airtime purchases automatically use your funded CheapDataHub wallet.

---

## 4. Electricity Bills & Prepaid Token Generation

- **SERVICE**: NEPA / DisCo Electricity Bill Payment and Prepaid Token Dispensing
- **PROVIDER**: VTpass
- **STATUS**: Integrated in `UtilityProvider.php` (Sandbox simulation active when key is empty)
- **OFFICIAL WEBSITE**: [https://www.vtpass.com/](https://www.vtpass.com/)
- **REGISTRATION URL**: [https://www.vtpass.com/register](https://www.vtpass.com/register)
- **API DOCUMENTATION**: [https://www.vtpass.com/api/documentation](https://www.vtpass.com/api/documentation)
- **BASE URL**: `https://api-service.vtpass.com/api` (Live) / `https://sandbox.vtpass.com/api` (Testing)
- **ENV VARIABLES**:
  ```text
  VTPASS_BASE_URL=https://api-service.vtpass.com/api
  VTPASS_API_KEY=
  VTPASS_PUBLIC_KEY=
  VTPASS_SECRET_KEY=
  ```
- **AUTHENTICATION**: Basic HTTP Auth (`api-key: <KEY>`, `secret-key: <SECRET>`)
- **SUPPORTED PRODUCTS**:
  - Ikeja Electric (IKEDC - `ikeja-electric`)
  - Eko Electricity (EKEDC - `eko-electric`)
  - Abuja Electricity (AEDC - `abuja-electric`)
  - Kano Electricity (KEDCO - `kano-electric`)
  - Port Harcourt Electricity (PHED - `portharcourt-electric`)
  - Ibadan Electricity (IBEDC - `ibadan-electric`)
  - Kaduna Electric (KAEDCO - `kaduna-electric`)
  - Jos Electricity (JED - `jos-electric`)
  - Enugu Electricity (EEDC - `enugu-electric`)
  - Benin Electricity (BEDC - `benin-electric`)
  - Aba Power (APLE - `aba-electric`)
  - Types: Prepaid (token generation) and Postpaid (bill settlement).
- **IMPLEMENTED ENDPOINTS**:
  - Verify Customer Meter: `POST /merchant-verify` (validates meter and returns customer name & address)
  - Vend Token: `POST /pay` (dispatches purchase, returns 20-digit prepaid token, units, and receipt)
- **LIVE ACTIVATION STEPS**:
  1. Register on VTpass at `https://www.vtpass.com/register`.
  2. Verify your email and complete KYC requirements.
  3. In developer settings, generate API Key and Secret Key.
  4. Top up your VTpass account balance.
  5. Add credentials to `.env`.

---

## 5. Cable TV Subscriptions

- **SERVICE**: DStv, GOtv, StarTimes & Showmax Bouquet Renewals
- **PROVIDER**: VTpass
- **STATUS**: Integrated in `UtilityProvider.php` (Sandbox simulation active when key is empty)
- **OFFICIAL WEBSITE**: [https://www.vtpass.com/](https://www.vtpass.com/)
- **REGISTRATION URL**: [https://www.vtpass.com/register](https://www.vtpass.com/register)
- **API DOCUMENTATION**: [https://www.vtpass.com/api/documentation](https://www.vtpass.com/api/documentation)
- **BASE URL**: `https://api-service.vtpass.com/api`
- **ENV VARIABLES**: Shared with `VTPASS_API_KEY`
- **AUTHENTICATION**: `api-key` & `secret-key` headers
- **SUPPORTED PRODUCTS**:
  - DStv (`dstv`): Padi, Yanga, Confam, Compact, Compact Plus, Premium
  - GOtv (`gotv`): Smallie, Jinja, Jolli, Max, Supa, Supa Plus
  - StarTimes (`startimes`): Nova, Basic, Smart, Classic, Super
  - Showmax (`showmax`): Mobile, Standard, Pro
- **IMPLEMENTED ENDPOINTS**:
  - Verify Smartcard / IUC: `POST /merchant-verify` (returns subscriber name, current active plan, renewal due date)
  - Fetch Bouquets: `GET /service-variations?serviceID=dstv`
  - Purchase Subscription: `POST /pay`
- **LIVE ACTIVATION STEPS**:
  - Automatically activated once `VTPASS_API_KEY` is added to `.env`.

---

## 6. Bulk SMS Gateway

- **SERVICE**: Targeted & Transactional Bulk SMS with Custom Sender ID
- **PROVIDER**: Termii (Primary) / SmartSMSSolutions (Secondary)
- **STATUS**: Integrated in `UtilityProvider.php`
- **OFFICIAL WEBSITE**: [https://termii.com/](https://termii.com/)
- **REGISTRATION URL**: [https://accounts.termii.com/register](https://accounts.termii.com/register)
- **API DOCUMENTATION**: [https://developers.termii.com/](https://developers.termii.com/)
- **BASE URL**: `https://api.ng.termii.com/api`
- **ENV VARIABLES**:
  ```text
  TERMII_BASE_URL=https://api.ng.termii.com/api
  TERMII_API_KEY=
  TERMII_SENDER_ID=Servora
  ```
- **AUTHENTICATION**: JSON body with `api_key`
- **SUPPORTED PRODUCTS**: High-priority DND delivery, OTP routes, and promotional messaging.
- **IMPLEMENTED ENDPOINTS**:
  - Send SMS: `POST /api/sms/send`
    - Payload: `{"to": "2348012345678", "from": "Servora", "sms": "Your message", "type": "plain", "channel": "generic", "api_key": "KEY"}`
  - Check Balance: `GET /api/get-balance?api_key=KEY`
- **LIVE ACTIVATION STEPS**:
  1. Register at `https://accounts.termii.com/register`.
  2. Request a custom Sender ID (e.g. `Servora`) under Sender ID management.
  3. Fund your Termii wallet.
  4. Copy your API Key and paste into `TERMII_API_KEY=` in `.env`.

---

## 7. Examination Result PINs & Scratch Cards

- **SERVICE**: Instant Examination Result Checker PINs & Registration Tokens
- **PROVIDER**: VTpass
- **STATUS**: Integrated in `UtilityProvider.php`
- **OFFICIAL WEBSITE**: [https://www.vtpass.com/](https://www.vtpass.com/)
- **REGISTRATION URL**: [https://www.vtpass.com/register](https://www.vtpass.com/register)
- **API DOCUMENTATION**: [https://www.vtpass.com/api/documentation](https://www.vtpass.com/api/documentation)
- **BASE URL**: `https://api-service.vtpass.com/api`
- **ENV VARIABLES**: Shared with `VTPASS_API_KEY`
- **SUPPORTED PRODUCTS**:
  - WAEC Scratch Card / Result Checker (`waec`)
  - NECO Result Token (`neco`)
  - JAMB Direct Entry & UTME PINs (`jamb`)
  - NABTEB Result Checker (`nabteb`)
- **IMPLEMENTED ENDPOINTS**:
  - Vend PINs: `POST /pay` (serviceID: waec, amount, quantity, phone)
  - Returns cards array containing `pin` and `serial` for instant display and receipt generation.
- **LIVE ACTIVATION STEPS**:
  - Automatically activated once `VTPASS_API_KEY` is added to `.env`.

---

## 8. Financial Integrity & Transaction Rules

Every transaction executed on Servora strictly follows this sequence:
1. **Idempotency Token Validation**: Prevents double-clicking from submitting double charges.
2. **Server-Side Pricing**: Prices are fetched and verified against current active admin pricing rules.
3. **Pessimistic Row Locking (`SELECT ... FOR UPDATE`)**: Wallet balances are checked and updated inside an atomic database transaction.
4. **Pending Transaction Creation**: An internal transaction record is stored *before* contacting the remote provider.
5. **Provider Execution**: Remote provider is called.
6. **Result Resolution**:
   - If provider succeeds: status is set to `successful`, token/result is recorded, customer is presented with instant result & copy button.
   - If provider fails or rejects: wallet balance is automatically rolled back / refunded inside the atomic transaction, status set to `failed`, and client is notified.
   - If provider times out: order marked `pending` for admin reconciliation or automated retry.

---

## 9. Upstream Provider Balance Monitoring

Servora includes a centralized balance monitoring engine:
- **Service Class**: `includes/ProviderBalanceService.php`
- **AJAX Endpoint**: `admin/api_provider_balances.php`
- **Dashboard UI**: Embedded inside `admin/dashboard.php`

### Features:
1. **Zero Secret Leakage**: Queries balances strictly server-side. No API keys, secret hashes, or bearer tokens are ever sent to client browsers.
2. **Complete Fault Isolation**: Each provider query runs in an independent `try/catch` block. If one provider times out or encounters network glitches, all other providers continue loading smoothly.
3. **Multi-Currency & Conversions**: Accurately formats balances in NGN (`₦`), RUB (`₽`) with approximate NGN conversions, and SMS units.
4. **Instant On-Demand Refresh**: Includes an AJAX refresh trigger that updates all cards in real time without reloading the dashboard.
