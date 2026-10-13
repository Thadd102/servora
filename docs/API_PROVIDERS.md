# Subnext API Providers & Developer Integration Guide

This document records the official API providers, endpoints, authentication methods, environment configuration, and live activation instructions for all 7 digital services on the Subnext platform.

---

## Architecture Summary

Subnext uses organized backend provider adapters located in `includes/`:
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
- **PROVIDER**: CheapDataHub
- **STATUS**: Integrated in `UtilityProvider.php` & `CheapDataHubClient.php`
- **OFFICIAL WEBSITE**: [https://www.cheapdatahub.ng/](https://www.cheapdatahub.ng/)
- **REGISTRATION URL**: [https://www.cheapdatahub.ng/register](https://www.cheapdatahub.ng/register)
- **API DOCUMENTATION**: [https://www.cheapdatahub.ng/api/docs/](https://www.cheapdatahub.ng/api/docs/)
- **BASE URL**: `https://www.cheapdatahub.ng/api/v1/resellers`
- **ENV VARIABLES**:
  ```text
  CHEAPDATAHUB_API_KEY=
  CHEAPDATAHUB_BASE_URL=https://www.cheapdatahub.ng/api/v1/resellers
  ```
- **AUTHENTICATION**: Bearer Token (`Authorization: Bearer <API_KEY>`)
- **SUPPORTED PRODUCTS**: MTN (`provider_id`: 1), Glo (`provider_id`: 2), Airtel (`provider_id`: 3), 9mobile / T2 (`provider_id`: 4)
- **IMPLEMENTED ENDPOINTS**:
  - Purchase Airtime: `POST /airtime/purchase/`
    - Payload: `{"provider_id": 1, "phone_number": "08012345678", "amount": 500}`
- **LIVE ACTIVATION STEPS**:
  - Automatically active when `CHEAPDATAHUB_API_KEY` is set in `.env` and your CheapDataHub wallet is funded.

---

## 4. Electricity Bills & Prepaid Token Generation

- **SERVICE**: NEPA / DisCo Electricity Bill Payment and Prepaid Token Dispensing
- **PROVIDER**: CheapDataHub
- **STATUS**: Integrated in `UtilityProvider.php` & `CheapDataHubClient.php`
- **OFFICIAL WEBSITE**: [https://www.cheapdatahub.ng/](https://www.cheapdatahub.ng/)
- **API DOCUMENTATION**: [https://www.cheapdatahub.ng/api/docs/](https://www.cheapdatahub.ng/api/docs/)
- **BASE URL**: `https://www.cheapdatahub.ng/api/v1/resellers`
- **ENV VARIABLES**: Shared with `CHEAPDATAHUB_API_KEY`
- **AUTHENTICATION**: Bearer Token (`Authorization: Bearer <API_KEY>`)
- **SUPPORTED DISCOS**:
  - 1: Abuja Electricity (AEDC)
  - 2: Eko Electricity (EKEDC)
  - 3: Ibadan Electricity (IBEDC)
  - 4: Ikeja Electric (IKEDC)
  - 5: Kaduna Electric (KAEDCO)
  - 6: Port Harcourt Electricity (PHED)
  - 7: Jos Electricity Distribution PLC (JEDPLC)
  - 8: Enugu Electricity (EEDC)
  - 9: Yola Electricity (YEDC)
  - 10: Benin Electricity (BEDC)
- **IMPLEMENTED ENDPOINTS**:
  - Verify Customer Meter: `POST /electricity/validate/`
    - Payload: `{"disco_id": 4, "meter_type": "prepaid", "meter_number": "12345678901"}`
  - Vend Token / Pay Bill: `POST /electricity/purchase/`
    - Payload: `{"disco_id": 4, "meter_number": "12345678901", "amount": 2000, "meter_type": "prepaid", "phone": "08012345678"}`
    - Returns token, units, and transaction receipt details.

---

## 5. Cable TV Subscriptions

- **SERVICE**: DStv, GOtv & StarTimes Bouquet Renewals and Activations
- **PROVIDER**: CheapDataHub
- **STATUS**: Integrated in `UtilityProvider.php` & `CheapDataHubClient.php`
- **OFFICIAL WEBSITE**: [https://www.cheapdatahub.ng/](https://www.cheapdatahub.ng/)
- **API DOCUMENTATION**: [https://www.cheapdatahub.ng/api/docs/](https://www.cheapdatahub.ng/api/docs/)
- **BASE URL**: `https://www.cheapdatahub.ng/api/v1/resellers`
- **ENV VARIABLES**: Shared with `CHEAPDATAHUB_API_KEY`
- **AUTHENTICATION**: Bearer Token (`Authorization: Bearer <API_KEY>`)
- **SUPPORTED PACKAGES**:
  - DStv: Padi (3), Yanga (6), Confam (7), Compact (8), Compact Plus (9), Premium (10)
  - GOtv: Smallie Monthly (4), Smallie Quarterly (11), Smallie Yearly (12), Jinja (13), Jolli (14), Max (15)
  - StarTimes: Nova (5, 16, 17), Basic (18-21), Classic (22-23), Super (24-25), Smart (26)
- **IMPLEMENTED ENDPOINTS**:
  - Verify Smartcard / IUC: `POST /cable/validate/`
    - Payload: `{"plan_id": 3, "smart_card_number": "1234567890"}`
  - Purchase Bouquet: `POST /cable/purchase/`
    - Payload: `{"plan_id": 3, "cardnumber": "1234567890", "phone": "08012345678"}`

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
  TERMII_SENDER_ID=Subnext
  ```
- **AUTHENTICATION**: JSON body with `api_key`
- **SUPPORTED PRODUCTS**: High-priority DND delivery, OTP routes, and promotional messaging.
- **IMPLEMENTED ENDPOINTS**:
  - Send SMS: `POST /api/sms/send`
    - Payload: `{"to": "2348012345678", "from": "Subnext", "sms": "Your message", "type": "plain", "channel": "generic", "api_key": "KEY"}`
  - Check Balance: `GET /api/get-balance?api_key=KEY`

---

## 7. Examination Result PINs & Scratch Cards

- **SERVICE**: Instant Examination Result Checker PINs
- **PROVIDER**: CheapDataHub
- **STATUS**: Integrated in `UtilityProvider.php` & `CheapDataHubClient.php`
- **OFFICIAL WEBSITE**: [https://www.cheapdatahub.ng/](https://www.cheapdatahub.ng/)
- **API DOCUMENTATION**: [https://www.cheapdatahub.ng/api/docs/](https://www.cheapdatahub.ng/api/docs/)
- **BASE URL**: `https://www.cheapdatahub.ng/api/v1/resellers`
- **ENV VARIABLES**: Shared with `CHEAPDATAHUB_API_KEY`
- **SUPPORTED PRODUCTS**:
  - WAEC Result Checker PIN (`product_id`: 1)
  - NECO Result Token (`product_id`: 2)
  - NABTEB Result Checker (`product_id`: 3)
- **IMPLEMENTED ENDPOINTS**:
  - Fetch Products: `GET /exam-pin/products/`
  - Purchase PINs: `POST /exam-pin/purchase/`
    - Payload: `{"product_id": 1, "quantity": 1}`
    - Returns generated PINs and Serial numbers for immediate client receipt.

---

## 8. Financial Integrity & Transaction Rules

Every transaction executed on Subnext strictly follows this sequence:
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

Subnext includes a centralized balance monitoring engine:
- **Service Class**: `includes/ProviderBalanceService.php`
- **AJAX Endpoint**: `admin/api_provider_balances.php`
- **Dashboard UI**: Embedded inside `admin/dashboard.php`

### Features:
1. **Zero Secret Leakage**: Queries balances strictly server-side. No API keys, secret hashes, or bearer tokens are ever sent to client browsers.
2. **Complete Fault Isolation**: Each provider query runs in an independent `try/catch` block. If one provider times out or encounters network glitches, all other providers continue loading smoothly.
3. **Multi-Currency & Conversions**: Accurately formats balances in NGN (`₦`), RUB (`₽`) with approximate NGN conversions, and SMS units.
4. **Instant On-Demand Refresh**: Includes an AJAX refresh trigger that updates all cards in real time without reloading the dashboard.
