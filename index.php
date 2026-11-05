<?php

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/whatsapp_widget.php";
require_once __DIR__ . "/includes/announcement_widget.php";
require_once __DIR__ . "/includes/app_install_widget.php";

// Check live maintenance status
$settingStmt = $pdo->prepare("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'client_login_enabled'
    LIMIT 1
");
$settingStmt->execute();
$setting = $settingStmt->fetchColumn();
$isMaintenanceActive = ($setting !== false && (string) $setting === "0");

// Check if user is logged in
$isUserLoggedIn = isLoggedIn();
$userRole = $isUserLoggedIn ? currentUserRole() : null;

// Determine destination if already logged in
$dashboardUrl = 'client/dashboard.php';
if ($isUserLoggedIn && in_array($userRole, ['admin', 'super_admin'], true)) {
    $dashboardUrl = 'admin/dashboard.php';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">

    <title>Subnext — Digital Services, Simplified | Cheap Data Bundles, Airtime & Utility Bills</title>
    <meta name="description"
        content="Subnext is Nigeria's premier automated platform for cheap data bundles, discount airtime recharge, electricity token bills, cable TV renewals, exam PINs, and foreign virtual numbers.">
    <meta name="theme-color" content="#3E37B7">

    <!-- Premium Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <!-- Core Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Dedicated Bulletproof Zero-Dependency Landing Stylesheet -->
    <link rel="stylesheet" href="assets/css/landing.css">

    <!-- Web App Manifest -->
    <link rel="manifest" href="manifest.json">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png">
    <link rel="shortcut icon" href="favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png">
</head>

<body class="sn-landing-body">

    <!-- MAINTENANCE ALERT BANNER (IF ACTIVE) -->
    <?php if ($isMaintenanceActive): ?>
        <aside aria-label="System Notice"
            style="background:#F59E0B; color:#0F172A; padding:10px 16px; text-align:center; font-size:13px; font-weight:700; width:100%;">
            <div class="sn-container" style="display:flex; align-items:center; justify-content:center; gap:8px;">
                <svg style="width:16px; height:16px; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>Notice: Subnext is currently in scheduled maintenance. Client sign-ins and new orders will resume
                    shortly.</span>
            </div>
        </aside>
    <?php endif; ?>

    <!-- =======================================================================
         NAVIGATION BAR
    ======================================================================== -->
    <header class="glass-nav">
        <div class="sn-container">
            <div class="sn-nav-row">

                <!-- LOGO -->
                <a href="index.php" class="sn-logo-link">
                    <div class="sn-logo-icon brand-gradient">
                        S
                    </div>
                    <div>
                        <span class="sn-logo-text-title">Subnext</span>
                        <span class="sn-logo-text-sub">Digital Services</span>
                    </div>
                </a>

                <!-- DESKTOP NAV LINKS -->
                <nav class="sn-desktop-nav">
                    <a href="#services">Services</a>
                    <a href="#how-it-works">How It Works</a>
                    <a href="#why-choose-us">Why Subnext</a>
                    <a href="#rates">Live Rates</a>
                    <a href="#faq">FAQ</a>
                </nav>

                <!-- AUTH ACTION BUTTONS -->
                <div class="sn-nav-actions">
                    <?php if ($isUserLoggedIn): ?>
                        <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="sn-btn-primary">
                            <span>Open Dashboard</span>
                            <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="sn-btn-ghost">
                            Sign In
                        </a>
                        <a href="register.php" class="sn-btn-primary">
                            Create Account
                        </a>
                    <?php endif; ?>
                </div>

                <!-- MOBILE HAMBURGER BUTTON -->
                <button type="button" id="mobile-menu-btn" class="sn-hamburger-btn" aria-label="Toggle Navigation">
                    <svg id="menu-icon-open" style="width:24px; height:24px;" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg id="menu-icon-close" style="width:24px; height:24px; display:none;" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

            </div>
        </div>

        <!-- MOBILE SLIDE-DOWN DRAWER -->
        <div id="mobile-menu" style="display:none;">
            <a href="#services" class="mobile-nav-link">Services</a>
            <a href="#how-it-works" class="mobile-nav-link">How It Works</a>
            <a href="#why-choose-us" class="mobile-nav-link">Why Choose Subnext</a>
            <a href="#rates" class="mobile-nav-link">Live Rates</a>
            <a href="#faq" class="mobile-nav-link">FAQ</a>

            <div
                style="margin-top:16px; padding-top:16px; border-top:1px solid #E2E8F0; display:flex; flex-direction:column; gap:10px;">
                <?php if ($isUserLoggedIn): ?>
                    <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="sn-btn-primary"
                        style="width:100%; text-align:center;">
                        Open Dashboard &rarr;
                    </a>
                <?php else: ?>
                    <a href="login.php" class="sn-btn-ghost" style="width:100%; text-align:center;">
                        Sign In
                    </a>
                    <a href="register.php" class="sn-btn-primary" style="width:100%; text-align:center;">
                        Create Free Account
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>

        <!-- Dynamic Announcements Banner / Popup -->
        <div class="sn-container" style="padding-top:16px;">
            <?php renderSubnextAnnouncements($isUserLoggedIn ? (int) $_SESSION['user_id'] : null); ?>
        </div>

        <!-- =======================================================================
             HERO SECTION (High-Impact, Spacious, Concise & Fully Responsive)
        ======================================================================== -->
        <section class="hero-mesh sn-hero-section">
            <div class="sn-container">
                <div class="sn-hero-grid">

                    <!-- Left Column: Copy & Actions -->
                    <div class="sn-hero-left">

                        <!-- Top Live Status Pill -->
                        <div class="sn-live-pill">
                            <span class="sn-live-dot"></span>
                            <span>Automated Gateway • &lt; 10s Instant Delivery</span>
                        </div>

                        <!-- Main Heading -->
                        <h1 class="sn-hero-title">
                            Digital Services, <span class="brand-gradient-text">Simplified.</span>
                        </h1>

                        <!-- Subtitle with Natural Keywords -->
                        <p class="sn-hero-lead">
                            Subnext is Nigeria's premier automated platform for instant <strong>cheap data
                                bundles</strong>, discount airtime recharge, electricity token bills, cable TV renewals,
                            exam PINs, and foreign virtual numbers at wholesale reseller prices.
                        </p>

                        <!-- CTA Buttons -->
                        <div class="sn-hero-actions">
                            <?php if ($isUserLoggedIn): ?>
                                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    class="sn-btn-hero-primary">
                                    <span>Access Dashboard</span>
                                    <svg style="width:18px; height:18px;" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                </a>
                            <?php else: ?>
                                <a href="register.php" class="sn-btn-hero-primary">
                                    <span>Create Free Account</span>
                                    <svg style="width:18px; height:18px;" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                </a>
                                <a href="login.php" class="sn-btn-hero-secondary">
                                    Sign In
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Trust Features Bar -->
                        <div class="sn-trust-bar">
                            <div class="sn-trust-chip">
                                <p class="sn-trust-chip-label">Processing</p>
                                <p class="sn-trust-chip-val">&lt; 10s Delivery ⚡</p>
                            </div>
                            <div class="sn-trust-chip">
                                <p class="sn-trust-chip-label">Security</p>
                                <p class="sn-trust-chip-val">Email OTP 🔒</p>
                            </div>
                            <div class="sn-trust-chip">
                                <p class="sn-trust-chip-label">Gateways</p>
                                <p class="sn-trust-chip-val">Card & Bank 💳</p>
                            </div>
                            <div class="sn-trust-chip">
                                <p class="sn-trust-chip-label">Helpdesk</p>
                                <p class="sn-trust-chip-val">24/7 WhatsApp 💬</p>
                            </div>
                        </div>

                    </div>

                    <!-- Right Column: Interactive Fintech Terminal Preview -->
                    <div>
                        <div class="sn-terminal">

                            <!-- Header -->
                            <div class="sn-term-header">
                                <div class="sn-term-brand">
                                    <div class="sn-term-icon brand-gradient">S</div>
                                    <div>
                                        <p class="sn-term-title">Subnext Automated Terminal</p>
                                        <p class="sn-term-status">● 99.98% System Uptime</p>
                                    </div>
                                </div>
                                <span class="sn-term-badge">
                                    Wholesale Hub
                                </span>
                            </div>

                            <!-- Balance Mockup -->
                            <div class="sn-term-wallet">
                                <div class="sn-term-wallet-top">
                                    <span class="sn-term-wallet-label">Subnext Wallet</span>
                                    <span class="sn-term-wallet-status">Automated</span>
                                </div>
                                <p class="sn-term-wallet-amount">₦24,850.00</p>
                                <p class="sn-term-wallet-sub">Instant Funding via Paystack & Flutterwave</p>
                            </div>

                            <!-- Quick Action Chips -->
                            <div class="sn-term-services-grid">
                                <div class="sn-term-chip">
                                    <span class="sn-term-chip-emoji">📶</span>
                                    <span class="sn-term-chip-name">Data</span>
                                </div>
                                <div class="sn-term-chip">
                                    <span class="sn-term-chip-emoji">📞</span>
                                    <span class="sn-term-chip-name">Airtime</span>
                                </div>
                                <div class="sn-term-chip">
                                    <span class="sn-term-chip-emoji">💡</span>
                                    <span class="sn-term-chip-name">Power</span>
                                </div>
                                <div class="sn-term-chip">
                                    <span class="sn-term-chip-emoji">🌐</span>
                                    <span class="sn-term-chip-name">Virtual</span>
                                </div>
                            </div>

                            <!-- Live Notification Simulation -->
                            <div class="sn-term-alert">
                                <div class="sn-term-alert-left">
                                    <span
                                        style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#34D399;"></span>
                                    <span>MTN 2.0GB SME Delivered</span>
                                </div>
                                <span class="sn-term-alert-time">&lt; 2.8s</span>
                            </div>

                            <!-- Network Labels -->
                            <div class="sn-term-footer">
                                <span>MTN • Airtel • Glo • 9mobile</span>
                                <span style="color:#FFFFFF; font-weight:800;">50+ Countries</span>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- =======================================================================
             SERVICES SECTION (6 Crisp, Beautiful Cards)
        ======================================================================== -->
        <section id="services" class="sn-services-section">
            <div class="sn-container">

                <div class="sn-section-header">
                    <p class="sn-section-tag">Available Digital Services</p>
                    <h2 class="sn-section-title">
                        Everything You Need, Powered Instantly
                    </h2>
                    <p class="sn-section-sub">
                        Enjoy cheap retail and wholesale pricing across high-speed telecommunications and essential
                        utilities.
                    </p>
                </div>

                <div class="sn-services-grid">

                    <!-- 1. DATA BUNDLES -->
                    <div class="sn-card">
                        <div>
                            <div class="sn-card-icon-box" style="background:#EDE9FE; color:#3E37B7;">📶</div>
                            <h3 class="sn-card-title">Cheap Data Bundles</h3>
                            <p class="sn-card-desc">
                                Superfast internet bundles for MTN, Airtel, Glo, and 9mobile. Instant top-ups with SME,
                                Corporate, and Gifting allocations.
                            </p>
                            <div class="sn-card-tags">
                                <span class="sn-tag">MTN SME from ₦250/GB</span>
                                <span class="sn-tag">Airtel Corporate</span>
                                <span class="sn-tag">Glo Gifting</span>
                            </div>
                        </div>
                        <a href="register.php" class="sn-card-action">
                            <span>Get Started</span>
                            <span>&rarr;</span>
                        </a>
                    </div>

                    <!-- 2. AIRTIME TOP-UP -->
                    <div class="sn-card">
                        <div>
                            <div class="sn-card-icon-box" style="background:#DCFCE7; color:#15803D;">📞</div>
                            <h3 class="sn-card-title">Discount Airtime VTU</h3>
                            <p class="sn-card-desc">
                                Recharge any Nigerian phone number with instant discount rates. Never pay full price
                                again for talk time.
                            </p>
                            <div class="sn-card-tags">
                                <span class="sn-tag">Up to 3% Discount</span>
                                <span class="sn-tag">Instant Delivery</span>
                                <span class="sn-tag">All Networks</span>
                            </div>
                        </div>
                        <a href="register.php" class="sn-card-action">
                            <span>Recharge Now</span>
                            <span>&rarr;</span>
                        </a>
                    </div>

                    <!-- 3. ELECTRICITY BILLS -->
                    <div class="sn-card">
                        <div>
                            <div class="sn-card-icon-box" style="background:#FEF3C7; color:#B45309;">💡</div>
                            <h3 class="sn-card-title">Electricity Tokens</h3>
                            <p class="sn-card-desc">
                                Pay prepaid and postpaid electricity bills seamlessly across IKEDC, EKEDC, AEDC, IBEDC,
                                PHED, and more.
                            </p>
                            <div class="sn-card-tags">
                                <span class="sn-tag">Instant Token Display</span>
                                <span class="sn-tag">Meter Name Check</span>
                                <span class="sn-tag">Zero Downtime</span>
                            </div>
                        </div>
                        <a href="register.php" class="sn-card-action">
                            <span>Pay Power Bill</span>
                            <span>&rarr;</span>
                        </a>
                    </div>

                    <!-- 4. CABLE TV SUBSCRIPTION -->
                    <div class="sn-card">
                        <div>
                            <div class="sn-card-icon-box" style="background:#FEE2E2; color:#B91C1C;">📺</div>
                            <h3 class="sn-card-title">Cable TV Renewal</h3>
                            <p class="sn-card-desc">
                                Renew your DStv, GOtv, and StarTimes packages instantly with immediate signal
                                reconnection and smartcard verification.
                            </p>
                            <div class="sn-card-tags">
                                <span class="sn-tag">DStv & GOtv</span>
                                <span class="sn-tag">StarTimes</span>
                                <span class="sn-tag">Instant Activation</span>
                            </div>
                        </div>
                        <a href="register.php" class="sn-card-action">
                            <span>Renew TV Package</span>
                            <span>&rarr;</span>
                        </a>
                    </div>

                    <!-- 5. EXAM RESULT PINS -->
                    <div class="sn-card">
                        <div>
                            <div class="sn-card-icon-box" style="background:#E0E7FF; color:#4338CA;">🎓</div>
                            <h3 class="sn-card-title">Exam Result PINs</h3>
                            <p class="sn-card-desc">
                                Purchase official WAEC, NECO, and NABTEB result checker PINs with immediate onscreen
                                token generation and SMS alerts.
                            </p>
                            <div class="sn-card-tags">
                                <span class="sn-tag">WAEC Direct</span>
                                <span class="sn-tag">NECO Token</span>
                                <span class="sn-tag">NABTEB PIN</span>
                            </div>
                        </div>
                        <a href="register.php" class="sn-card-action">
                            <span>Get Exam PIN</span>
                            <span>&rarr;</span>
                        </a>
                    </div>

                    <!-- 6. FOREIGN VIRTUAL NUMBERS -->
                    <div class="sn-card">
                        <div>
                            <div class="sn-card-icon-box" style="background:#CFFAFE; color:#0E7490;">🌐</div>
                            <h3 class="sn-card-title">Foreign Virtual Numbers</h3>
                            <p class="sn-card-desc">
                                Rent temporary USA, UK, Canada & European numbers for instant SMS OTP verification
                                across WhatsApp, Telegram, and 500+ apps.
                            </p>
                            <div class="sn-card-tags">
                                <span class="sn-tag">USA / UK / Canada</span>
                                <span class="sn-tag">WhatsApp OTP</span>
                                <span class="sn-tag">Automated Refund</span>
                            </div>
                        </div>
                        <a href="register.php" class="sn-card-action">
                            <span>Rent Numbers</span>
                            <span>&rarr;</span>
                        </a>
                    </div>

                </div>

            </div>
        </section>

        <!-- =======================================================================
             HOW IT WORKS (3 Simple Steps)
        ======================================================================== -->
        <section id="how-it-works" class="sn-how-section">
            <div class="sn-container">

                <div class="sn-section-header">
                    <p class="sn-section-tag">Smooth & Painless</p>
                    <h2 class="sn-section-title">
                        How Subnext Works in 3 Quick Steps
                    </h2>
                    <p class="sn-section-sub">
                        Get started in less than two minutes with verified security and instant execution.
                    </p>
                </div>

                <div class="sn-steps-grid">

                    <!-- Step 1 -->
                    <div class="sn-step-box">
                        <span class="sn-step-number">01</span>
                        <div class="sn-step-icon" style="background:#EDE9FE; color:#3E37B7;">👤</div>
                        <h3 class="sn-step-title">Create Account</h3>
                        <p class="sn-step-text">
                            Register with your basic info. Verify your email with our secure 6-digit OTP code to protect
                            your wallet and account credentials.
                        </p>
                    </div>

                    <!-- Step 2 -->
                    <div class="sn-step-box">
                        <span class="sn-step-number">02</span>
                        <div class="sn-step-icon" style="background:#DCFCE7; color:#15803D;">💳</div>
                        <h3 class="sn-step-title">Fund Your Wallet</h3>
                        <p class="sn-step-text">
                            Deposit money securely via Paystack, Flutterwave, ATM Card, or dedicated virtual bank
                            transfer with automatic zero-wait crediting.
                        </p>
                    </div>

                    <!-- Step 3 -->
                    <div class="sn-step-box">
                        <span class="sn-step-number">03</span>
                        <div class="sn-step-icon" style="background:#FEF3C7; color:#B45309;">⚡</div>
                        <h3 class="sn-step-title">Order & Enjoy</h3>
                        <p class="sn-step-text">
                            Select any service — data, airtime, power bills, or foreign virtual numbers. Our high-speed
                            APIs dispatch your order in seconds.
                        </p>
                    </div>

                </div>

            </div>
        </section>

        <!-- =======================================================================
             WHY CHOOSE SUBNEXT
        ======================================================================== -->
        <section id="why-choose-us" class="sn-why-section">
            <div class="sn-container">
                <div class="sn-why-grid">

                    <!-- Left: Content -->
                    <div>
                        <p class="sn-section-tag" style="text-align:left;">Why Subnext?</p>
                        <h2 class="sn-section-title" style="text-align:left;">
                            Engineered for Speed, Reliability, and Reseller Profitability
                        </h2>
                        <p class="sn-section-sub" style="text-align:left; margin-top:16px;">
                            Whether you are purchasing for personal everyday use or running a high-volume telecom resale
                            business, Subnext gives you unbeatable uptime, transparent pricing, and instant resolutions.
                        </p>

                        <div class="sn-why-points">

                            <div class="sn-why-point">
                                <div class="sn-why-point-icon">⚡</div>
                                <div>
                                    <h4 class="sn-why-point-title">Sub-10-Second API Delivery</h4>
                                    <p class="sn-why-point-desc">Direct fiber telecommunication routing ensures your
                                        mobile data and airtime hit target lines almost instantly.</p>
                                </div>
                            </div>

                            <div class="sn-why-point">
                                <div class="sn-why-point-icon">🔒</div>
                                <div>
                                    <h4 class="sn-why-point-title">Multi-Layer Financial Security</h4>
                                    <p class="sn-why-point-desc">Every client balance is guarded with 256-bit SSL
                                        encryption, email OTP verification, and strict transaction isolation.</p>
                                </div>
                            </div>

                            <div class="sn-why-point">
                                <div class="sn-why-point-icon">💰</div>
                                <div>
                                    <h4 class="sn-why-point-title">Wholesale Reseller Margins</h4>
                                    <p class="sn-why-point-desc">Enjoy wholesale data prices starting at ₦250/GB, giving
                                        resellers healthy profit margins on every single gigabyte sold.</p>
                                </div>
                            </div>

                            <div class="sn-why-point">
                                <div class="sn-why-point-icon">💬</div>
                                <div>
                                    <h4 class="sn-why-point-title">Dedicated Human WhatsApp Support</h4>
                                    <p class="sn-why-point-desc">Our admin-backed WhatsApp customer desk is available
                                        24/7 to attend to order inquiries and assist you immediately.</p>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Right: Metrics Grid -->
                    <div>
                        <div class="sn-metrics-grid">
                            <div class="sn-metric-box">
                                <p class="sn-metric-num">99.9%</p>
                                <p class="sn-metric-label">System Uptime</p>
                            </div>
                            <div class="sn-metric-box">
                                <p class="sn-metric-num">&lt; 10s</p>
                                <p class="sn-metric-label">Average Delivery</p>
                            </div>
                            <div class="sn-metric-box">
                                <p class="sn-metric-num">50k+</p>
                                <p class="sn-metric-label">Transactions Completed</p>
                            </div>
                            <div class="sn-metric-box">
                                <p class="sn-metric-num">24/7</p>
                                <p class="sn-metric-label">WhatsApp Helpdesk</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- =======================================================================
             LIVE RATES PREVIEW
        ======================================================================== -->
        <section id="rates" class="sn-rates-section">
            <div class="sn-container">

                <div class="sn-section-header">
                    <p class="sn-section-tag">Transparent Reseller Rates</p>
                    <h2 class="sn-section-title">
                        Popular Data Pricing at a Glance
                    </h2>
                    <p class="sn-section-sub">
                        Direct wholesale rates with zero hidden fees. Fund and purchase immediately.
                    </p>
                </div>

                <div class="sn-table-wrapper">
                    <table class="sn-rates-table">
                        <thead>
                            <tr>
                                <th>Network</th>
                                <th>Plan Type</th>
                                <th>Data Volume</th>
                                <th>Validity</th>
                                <th>Reseller Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>MTN</strong></td>
                                <td>SME Data</td>
                                <td>1.0 GB</td>
                                <td>1 Day</td>
                                <td style="font-weight:800; color:#3E37B7;">₦399.00</td>
                                <td><span
                                        style="background:#DCFCE7; color:#15803D; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">●
                                        Active</span></td>
                            </tr>
                            <tr>
                                <td><strong>MTN</strong></td>
                                <td>SME Data</td>
                                <td>2.0 GB</td>
                                <td>2 Days</td>
                                <td style="font-weight:800; color:#3E37B7;">₦610.00</td>
                                <td><span
                                        style="background:#DCFCE7; color:#15803D; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">●
                                        Active</span></td>
                            </tr>
                            <tr>
                                <td><strong>MTN</strong></td>
                                <td>Corporate Gifting</td>
                                <td>5.0 GB</td>
                                <td>7 Days</td>
                                <td style="font-weight:800; color:#3E37B7;">₦1,725.00</td>
                                <td><span
                                        style="background:#DCFCE7; color:#15803D; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">●
                                        Active</span></td>
                            </tr>
                            <tr>
                                <td><strong>Airtel</strong></td>
                                <td>Corporate Gifting</td>
                                <td>1.0 GB</td>
                                <td>1 Day</td>
                                <td style="font-weight:800; color:#3E37B7;">₦390.00</td>
                                <td><span
                                        style="background:#DCFCE7; color:#15803D; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">●
                                        Active</span></td>
                            </tr>
                            <tr>
                                <td><strong>Glo</strong></td>
                                <td>Gifting Bundle</td>
                                <td>1.0 GB</td>
                                <td>2 Days</td>
                                <td style="font-weight:800; color:#3E37B7;">₦560.00</td>
                                <td><span
                                        style="background:#DCFCE7; color:#15803D; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">●
                                        Active</span></td>
                            </tr>
                            <tr>
                                <td><strong>9mobile</strong></td>
                                <td>SME Data</td>
                                <td>1.5 GB</td>
                                <td>30 Days</td>
                                <td style="font-weight:800; color:#3E37B7;">₦350.00</td>
                                <td><span
                                        style="background:#DCFCE7; color:#15803D; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">●
                                        Active</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="text-align:center; margin-top:28px;">
                    <a href="register.php" class="sn-btn-primary" style="padding:14px 28px; font-size:15px;">
                        <span>View All 150+ Plans Inside Dashboard</span>
                        <span>&rarr;</span>
                    </a>
                </div>

            </div>
        </section>

        <!-- =======================================================================
             FREQUENTLY ASKED QUESTIONS (FAQ)
        ======================================================================== -->
        <section id="faq" class="sn-faq-section">
            <div class="sn-container">

                <div class="sn-section-header">
                    <p class="sn-section-tag">Got Questions?</p>
                    <h2 class="sn-section-title">
                        Frequently Asked Questions
                    </h2>
                    <p class="sn-section-sub">
                        Everything you need to know about Subnext, funding, security, and deliveries.
                    </p>
                </div>

                <div class="sn-faq-list">

                    <div class="sn-faq-item">
                        <details>
                            <summary>
                                <span>How long does it take for data or airtime to arrive?</span>
                                <span>+</span>
                            </summary>
                            <p class="sn-faq-answer">
                                All mobile data, airtime, electricity tokens, and cable renewals are fully automated.
                                Under normal network conditions, transactions are dispatched and confirmed on the
                                recipient line in under 10 seconds.
                            </p>
                        </details>
                    </div>

                    <div class="sn-faq-item">
                        <details>
                            <summary>
                                <span>How do I fund my Subnext wallet?</span>
                                <span>+</span>
                            </summary>
                            <p class="sn-faq-answer">
                                You can fund your wallet 24/7 via debit card or direct bank transfer through verified
                                payment processors including Paystack and Flutterwave. Your wallet balance is updated
                                automatically without manual intervention.
                            </p>
                        </details>
                    </div>

                    <div class="sn-faq-item">
                        <details>
                            <summary>
                                <span>What happens if a transaction fails?</span>
                                <span>+</span>
                            </summary>
                            <p class="sn-faq-answer">
                                Subnext features automatic refund protection. If a telecommunications provider fails or
                                rejects an order, your wallet balance is immediately refunded in full without hassle.
                            </p>
                        </details>
                    </div>

                    <div class="sn-faq-item">
                        <details>
                            <summary>
                                <span>Why is email OTP verification required during registration?</span>
                                <span>+</span>
                            </summary>
                            <p class="sn-faq-answer">
                                We enforce mandatory 6-digit email OTP verification before client accounts are
                                activated. This guarantees that your email address is authentic, prevents unauthorized
                                bots, and protects your future wallet balances from identity fraud.
                            </p>
                        </details>
                    </div>

                    <div class="sn-faq-item">
                        <details>
                            <summary>
                                <span>How do I contact customer support?</span>
                                <span>+</span>
                            </summary>
                            <p class="sn-faq-answer">
                                You can click the floating green WhatsApp icon on your screen anytime to start a live
                                conversation with our support team, or open a support ticket directly from your client
                                dashboard.
                            </p>
                        </details>
                    </div>

                </div>

            </div>
        </section>

    </main>

    <!-- =======================================================================
         FOOTER
    ======================================================================== -->
    <footer class="sn-footer">
        <div class="sn-container">
            <div class="sn-footer-grid">

                <!-- Col 1: Brand Info -->
                <div class="sn-footer-col">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                        <div class="sn-term-icon brand-gradient">S</div>
                        <span style="font-size:22px; font-weight:900; color:#FFFFFF;">Subnext</span>
                    </div>
                    <p style="font-size:13px; line-height:1.6; color:#94A3B8; max-width:320px; margin:0 0 16px;">
                        Nigeria's premier automated platform for instant cheap data bundles, discount airtime, utility
                        bill tokens, and foreign virtual numbers.
                    </p>
                    <div style="display:flex; gap:8px;">
                        <span
                            style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:4px 10px; font-size:11px; color:#94A3B8;">SSL
                            256-Bit</span>
                        <span
                            style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:4px 10px; font-size:11px; color:#94A3B8;">Email
                            OTP Verified</span>
                    </div>
                </div>

                <!-- Col 2: Services -->
                <div class="sn-footer-col">
                    <h4>Services</h4>
                    <ul class="sn-footer-links">
                        <li><a href="#services">Cheap Data Bundles</a></li>
                        <li><a href="#services">Discount Airtime VTU</a></li>
                        <li><a href="#services">Electricity Tokens</a></li>
                        <li><a href="#services">Cable TV Renewal</a></li>
                        <li><a href="#services">Foreign Virtual Numbers</a></li>
                    </ul>
                </div>

                <!-- Col 3: Quick Links -->
                <div class="sn-footer-col">
                    <h4>Quick Links</h4>
                    <ul class="sn-footer-links">
                        <li><a href="login.php">Sign In</a></li>
                        <li><a href="register.php">Create Free Account</a></li>
                        <li><a href="#how-it-works">How It Works</a></li>
                        <li><a href="#rates">Pricing Rates</a></li>
                        <li><a href="#faq">FAQ</a></li>
                    </ul>
                </div>

                <!-- Col 4: Support -->
                <div class="sn-footer-col">
                    <h4>Customer Care</h4>
                    <p style="font-size:13px; color:#94A3B8; margin:0 0 12px; line-height:1.5;">
                        Need quick assistance? Our support team is online 24/7.
                    </p>
                    <ul class="sn-footer-links">
                        <li style="color:#FFFFFF; font-size:13px; font-weight:700;">WhatsApp Support Live Desk</li>
                        <li><a href="client/support.php">Open Support Ticket</a></li>
                    </ul>
                </div>

            </div>

            <!-- Footer Bottom -->
            <div class="sn-footer-bottom">
                <p style="margin:0;">© <?= date('Y') ?> Subnext Digital Services. All rights reserved.</p>
                <p style="margin:0;">Secured with 256-bit SSL • Verified Automated Gateways</p>
            </div>
        </div>
    </footer>

    <!-- =======================================================================
         FLOATING WHATSAPP SUPPORT WIDGET (ADMIN CONTROLLED)
    ======================================================================== -->
    <?php renderSubnextWhatsAppWidget(false); ?>

    <!-- MOBILE MENU ACCORDION / TOGGLE SCRIPT -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var menuBtn = document.getElementById('mobile-menu-btn');
            var mobileMenu = document.getElementById('mobile-menu');
            var iconOpen = document.getElementById('menu-icon-open');
            var iconClose = document.getElementById('menu-icon-close');

            if (menuBtn && mobileMenu) {
                menuBtn.addEventListener('click', function () {
                    var isOpen = mobileMenu.style.display === 'block';
                    if (isOpen) {
                        mobileMenu.style.display = 'none';
                        if (iconOpen) iconOpen.style.display = 'block';
                        if (iconClose) iconClose.style.display = 'none';
                    } else {
                        mobileMenu.style.display = 'block';
                        if (iconOpen) iconOpen.style.display = 'none';
                        if (iconClose) iconClose.style.display = 'block';
                    }
                });

                var navLinks = document.querySelectorAll('.mobile-nav-link');
                navLinks.forEach(function (link) {
                    link.addEventListener('click', function () {
                        mobileMenu.style.display = 'none';
                        if (iconOpen) iconOpen.style.display = 'block';
                        if (iconClose) iconClose.style.display = 'none';
                    });
                });
            }
        });
    </script>

    <!-- =======================================================================
         PWA INSTALLATION PROMPT / APK BANNER (RESPONSIVE & DISMISSIBLE)
    ======================================================================== -->
    <?php renderSubnextInstallWidget(false); ?>

    <!-- PWA & Push Service Worker Registration -->
    <script src="assets/js/push_manager.js"></script>
    <script src="assets/js/pwa_installer.js"></script>

    <script src="assets/js/loader.js" defer></script>
</body>

</html>