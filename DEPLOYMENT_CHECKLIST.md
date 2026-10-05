# Servora deployment checklist

1. Copy `.env.example` to `.env`; set `APP_ENV=production`, HTTPS `APP_URL`, database and provider/payment credentials.
2. Keep `.env`, SQL dumps, test scripts and logs outside public access. The included `.htaccess` blocks common sensitive files.
3. Import the latest database schema, then configure providers/plans from the admin dashboard.
4. Set `ALLOW_ADMIN_BOOTSTRAP=0` after the first super-admin exists.
5. Use HTTPS. Production sessions automatically use Secure + HttpOnly + SameSite=Lax cookies.
6. Configure Paystack/Flutterwave callback/webhook URLs exactly to the production HTTPS domain and verify webhook signatures.
7. Configure SMTP with an app-specific credential; do not use a normal mailbox password.
8. Put real API keys only in `.env`; never in PHP, JavaScript, screenshots, SQL dumps, or Git.
9. Remove/deny development endpoints. This build returns 404 for known test/dev endpoints when `APP_ENV=production`.
10. Run `php -l` on all PHP files, test registration/login/logout, wallet funding, callbacks, data purchase/refund/reconciliation, utility and foreign number flows, admin roles, maintenance/access controls, and mobile layouts before launch.
11. Performance: enable Brotli/Gzip at hosting/CDN level, HTTP/2 or HTTP/3, PHP OPcache, image compression/WebP, and browser caching. Lighthouse scores depend on hosting, network, page content and third-party scripts, so a fixed 100 score cannot be guaranteed.
12. Back up both source code and the MySQL database before every production change.
