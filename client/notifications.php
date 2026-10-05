<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = (int) $_SESSION['user_id'];

/* =========================
   CSRF TOKEN
========================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$success = '';
$error = '';

/* =========================
   MARK NOTIFICATION AS READ
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        $error = "Invalid security token.";

    } else {

        $notificationId = filter_input(
            INPUT_POST,
            'notification_id',
            FILTER_VALIDATE_INT
        );

        if (!$notificationId) {

            $error = "Invalid notification.";

        } else {

            try {

                /*
                 * IMPORTANT:
                 * The notification must belong to the
                 * currently logged-in client.
                 */

                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ?
                      AND user_id = ?
                ");

                $stmt->execute([
                    $notificationId,
                    $userId
                ]);

                if ($stmt->rowCount() > 0) {
                    $success = "Notification marked as read.";
                }

            } catch (Throwable $e) {

                $error = "Unable to update notification.";
            }
        }
    }
}

/* =========================
   FETCH NOTIFICATIONS
========================= */

$stmt = $pdo->prepare("
    SELECT
        n.id,
        n.title,
        n.message,
        n.is_read,
        n.created_at,
        n.request_id,
        sr.request_code
    FROM notifications n

    LEFT JOIN service_requests sr
        ON sr.id = n.request_id
       AND sr.user_id = n.user_id

    WHERE n.user_id = ?

    ORDER BY n.created_at DESC
");

$stmt->execute([$userId]);

$notifications = $stmt->fetchAll();

$stmtWallet = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmtWallet->execute([$userId]);
$wallet = $stmtWallet->fetch(PDO::FETCH_ASSOC);
$walletBalance = $wallet ? (float)$wallet["balance"] : 0.00;

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Notifications - Servora</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        servora: {
                            50: '#F5F3FF',
                            100: '#EDE9FE',
                            200: '#DDD6FE',
                            500: '#635BDB',
                            600: '#5146C7',
                            700: '#3E37B7',
                            800: '#312E81',
                            900: '#1E1B4B'
                        }
                    }
                }
            }
        }
    </script>

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">


<!-- =========================
     TOP HEADER
========================= -->

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Servora</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Notifications</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <div class="flex items-center gap-2.5">
            <a href="notifications.php" class="relative flex h-10 w-10 items-center justify-center rounded-xl bg-servora-50 text-servora-700" aria-label="Notifications">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 1-5.714 0M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </a>
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm" title="My Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- HERO BANNER CARD -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="dashboard.php" class="text-sm font-semibold text-white/70 hover:text-white">
            ← Dashboard
        </a>

        <div class="mt-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-white/70">
                    Servora Activity
                </p>
                <h1 class="mt-1 text-3xl font-black">
                    Notifications
                </h1>
                <p class="mt-2 max-w-xl text-sm text-white/70">
                    Stay updated with your service orders, delivery tokens, and account security alerts.
                </p>
            </div>
            <div>
                <span class="rounded-xl bg-white/10 backdrop-blur border border-white/20 px-3.5 py-2 text-xs font-bold text-white">
                    <?= count($notifications) ?> Alerts
                </span>
            </div>
        </div>
    </section>

    <!-- STANDARDIZED WALLET BALANCE CARD -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($walletBalance, 2) ?>
                </p>
            </div>
            <a href="fund_wallet.php" class="rounded-xl bg-servora-50 px-4 py-2.5 text-sm font-bold text-servora-700 hover:bg-servora-100 transition">
                Fund Wallet
            </a>
        </div>
    </section>



    <!-- =========================
         ALERTS
    ========================= -->

    <?php if ($success): ?>

        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">

            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2.5"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M5 13l4 4L19 7"
                    />
                </svg>

            </div>

            <div>

                <p class="text-sm font-bold">
                    Done
                </p>

                <p class="mt-0.5 text-sm text-emerald-700">
                    <?= htmlspecialchars($success) ?>
                </p>

            </div>

        </div>

    <?php endif; ?>



    <?php if ($error): ?>

        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800">

            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-100">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2.5"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 9v3m0 3h.01M10.3 4.6l-7.1 12.3A2 2 0 005 20h14a2 2 0 001.8-3.1L13.7 4.6a2 2 0 00-3.4 0z"
                    />
                </svg>

            </div>

            <div>

                <p class="text-sm font-bold">
                    Something went wrong
                </p>

                <p class="mt-0.5 text-sm text-red-700">
                    <?= htmlspecialchars($error) ?>
                </p>

            </div>

        </div>

    <?php endif; ?>



    <!-- =========================
         NOTIFICATIONS
    ========================= -->

    <?php if (empty($notifications)): ?>


        <!-- Empty State -->

        <div class="rounded-3xl border border-slate-200 bg-white px-6 py-14 text-center shadow-sm sm:px-10">

            <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-servora-50 text-servora-700">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-10 w-10"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.6"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5"
                    />

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M10 20h4"
                    />
                </svg>

            </div>

            <h3 class="mt-6 text-lg font-bold text-slate-900">
                No notifications yet
            </h3>

            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                You will see updates about your service requests, payments, and account here.
            </p>

            <a
                href="dashboard.php"
                class="mt-6 inline-flex min-h-11 items-center justify-center rounded-2xl bg-servora-700 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-servora-700/20 transition hover:bg-servora-800"
            >
                Go to Dashboard
            </a>

        </div>


    <?php else: ?>


        <div class="space-y-4">


            <?php foreach ($notifications as $notification): ?>


                <?php

                    $isUnread = ((int) $notification['is_read'] === 0);

                ?>


                <!-- Notification Card -->

                <article
                    class="<?= $isUnread
                        ? 'border-servora-200 bg-servora-50/60'
                        : 'border-slate-200 bg-white'
                    ?>
                    overflow-hidden rounded-3xl border shadow-sm transition hover:shadow-md"
                >


                    <!-- Card Main -->

                    <div class="p-5 sm:p-6">


                        <div class="flex items-start gap-4">


                            <!-- Icon -->

                            <div
                                class="<?= $isUnread
                                    ? 'bg-servora-100 text-servora-700'
                                    : 'bg-slate-100 text-slate-500'
                                ?>
                                flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl"
                            >

                                <?php if ($isUnread): ?>

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-6 w-6"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5"
                                        />

                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M10 20h4"
                                        />
                                    </svg>

                                <?php else: ?>

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-6 w-6"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M5 13l4 4L19 7"
                                        />
                                    </svg>

                                <?php endif; ?>

                            </div>



                            <!-- Content -->

                            <div class="min-w-0 flex-1">


                                <!-- Title + Badge -->

                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">

                                    <div class="min-w-0">

                                        <h3 class="text-base font-extrabold leading-6 text-slate-900 sm:text-lg">
                                            <?= htmlspecialchars($notification['title']) ?>
                                        </h3>

                                        <p class="mt-1 text-xs font-medium text-slate-400">
                                            <?= htmlspecialchars($notification['created_at']) ?>
                                        </p>

                                    </div>


                                    <?php if ($isUnread): ?>

                                        <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-servora-100 px-2.5 py-1 text-xs font-bold text-servora-700">

                                            <span class="h-1.5 w-1.5 rounded-full bg-servora-600"></span>

                                            Unread

                                        </span>

                                    <?php else: ?>

                                        <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500">

                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>

                                            Read

                                        </span>

                                    <?php endif; ?>

                                </div>



                                <!-- Message -->

                                <div class="mt-4 text-sm leading-7 text-slate-600">

                                    <?= nl2br(
                                        htmlspecialchars($notification['message'])
                                    ) ?>

                                </div>



                                <!-- Actions -->

                                <?php if (
                                    !empty($notification['request_id']) ||
                                    $isUnread
                                ): ?>

                                    <div class="mt-5 flex flex-col gap-2 sm:flex-row">


                                        <?php if (!empty($notification['request_id'])): ?>

                                            <a
                                                href="request_tracker.php?id=<?= (int) $notification['request_id'] ?>"
                                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-servora-700 px-5 py-2.5 text-sm font-bold text-white shadow-md shadow-servora-700/15 transition hover:bg-servora-800"
                                            >

                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-4 w-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M9 5l7 7-7 7"
                                                    />
                                                </svg>

                                                View Request

                                            </a>

                                        <?php endif; ?>



                                        <?php if ($isUnread): ?>

                                            <form method="POST" class="w-full sm:w-auto">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="notification_id"
                                                    value="<?= (int) $notification['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                                >

                                                    <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        class="h-4 w-4"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            d="M5 13l4 4L19 7"
                                                        />
                                                    </svg>

                                                    Mark as Read

                                                </button>

                                            </form>

                                        <?php endif; ?>


                                    </div>

                                <?php endif; ?>


                            </div>

                        </div>

                    </div>

                </article>


            <?php endforeach; ?>


        </div>


    <?php endif; ?>



    <!-- =========================
         BOTTOM ACTION
    ========================= -->

    <div class="mt-7">

        <a
            href="dashboard.php"
            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 sm:w-auto"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 19l-7-7 7-7"
                />
            </svg>

            Back to Dashboard

        </a>

    </div>


</main>



<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>
</html>
