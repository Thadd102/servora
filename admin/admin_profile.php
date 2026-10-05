<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$success = '';
$error = '';

/* =========================
   FETCH CURRENT ADMIN
========================= */

$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, status, created_at
    FROM users
    WHERE id = ?
      AND role IN ('admin', 'super_admin')
    LIMIT 1
");

$stmt->execute([$userId]);

$admin = $stmt->fetch();

if (!$admin) {
    die("Admin account not found.");
}

/* =========================
   UPDATE PROFILE
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $error = "Invalid security token.";
    } else {

        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($fullName === '') {

            $error = "Full name is required.";

        } elseif (strlen($fullName) < 2) {

            $error = "Full name is too short.";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = "Please enter a valid email address.";

        } else {

            try {

                /* =========================
                   CHECK EMAIL
                ========================= */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                      AND id != ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $email,
                    $userId
                ]);

                if ($stmt->fetch()) {

                    $error = "That email address is already in use.";

                } else {

                    /* =========================
                       UPDATE PROFILE
                    ========================= */

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = ?,
                            email = ?,
                            phone = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $fullName,
                        $email,
                        $phone,
                        $userId
                    ]);

                    /* =========================
                       UPDATE SESSION
                    ========================= */

                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;

                    /* =========================
                       ACTIVITY LOG
                    ========================= */

                    $stmt = $pdo->prepare("
                        INSERT INTO activity_logs
                        (
                            user_id,
                            action,
                            description
                        )
                        VALUES (?, ?, ?)
                    ");

                    $stmt->execute([
                        $userId,
                        'admin_profile_updated',
                        'Admin updated their profile.'
                    ]);

                    $success = "Profile updated successfully.";

                    /* =========================
                       REFRESH ADMIN DATA
                    ========================= */

                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            full_name,
                            email,
                            phone,
                            role,
                            status,
                            created_at
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$userId]);

                    $admin = $stmt->fetch();
                }

            } catch (Throwable $e) {

                $error = "Unable to update your profile. Please try again.";
            }
        }
    }
}

/* =========================
   HELPERS
========================= */

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function roleClasses(string $role): string
{
    return match ($role) {

        'super_admin' =>
            'bg-amber-50 text-amber-700 border-amber-200',

        'admin' =>
            'bg-indigo-50 text-indigo-700 border-indigo-200',

        default =>
            'bg-slate-100 text-slate-600 border-slate-200'
    };
}

function statusClasses(string $status): string
{
    return match ($status) {

        'active' =>
            'bg-emerald-50 text-emerald-700 border-emerald-200',

        'suspended' =>
            'bg-red-50 text-red-700 border-red-200',

        'inactive' =>
            'bg-slate-100 text-slate-600 border-slate-200',

        default =>
            'bg-slate-100 text-slate-600 border-slate-200'
    };
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Profile - Servora</title>

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


<body class="min-h-screen bg-slate-50 text-slate-800">


<div class="min-h-screen">


    <!-- HEADER -->

    <header class="border-b border-slate-200 bg-white">

        <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:px-8">

            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">


                <div>

                    <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-servora-50 px-3 py-1.5 text-xs font-semibold text-servora-700">

                        <span class="h-2 w-2 rounded-full bg-servora-600"></span>

                        Account Settings

                    </div>


                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">

                        Admin Profile

                    </h1>


                    <p class="mt-1 text-sm leading-6 text-slate-500">

                        Manage your Servora administrator account information.

                    </p>

                </div>


                <a
                    href="dashboard.php"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                >

                    <svg
                        class="mr-2 h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 19l-7-7 7-7"
                        />

                    </svg>

                    Dashboard

                </a>

            </div>

        </div>

    </header>


    <!-- MAIN -->

    <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">


        <!-- PROFILE HERO -->

        <section class="mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-servora-900 via-servora-800 to-servora-600 shadow-lg">

            <div class="p-6 sm:p-8">

                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">


                    <!-- AVATAR -->

                    <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-3xl bg-white/15 text-3xl font-bold text-white ring-1 ring-white/20">

                        <?= e(
                            strtoupper(
                                substr(
                                    $admin['full_name'],
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    </div>


                    <div class="min-w-0">

                        <p class="text-sm font-medium text-indigo-200">
                            Administrator Account
                        </p>


                        <h2 class="mt-1 truncate text-2xl font-bold text-white sm:text-3xl">

                            <?= e($admin['full_name']) ?>

                        </h2>


                        <p class="mt-1 break-all text-sm text-indigo-100">

                            <?= e($admin['email']) ?>

                        </p>

                    </div>

                </div>

            </div>

        </section>


        <!-- SUCCESS -->

        <?php if ($success): ?>

            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">

                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-emerald-600 shadow-sm">

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M5 13l4 4L19 7"
                        />

                    </svg>

                </div>


                <div>

                    <p class="font-semibold text-emerald-800">
                        Profile updated
                    </p>

                    <p class="mt-1 text-sm text-emerald-700">

                        <?= e($success) ?>

                    </p>

                </div>

            </div>

        <?php endif; ?>


        <!-- ERROR -->

        <?php if ($error): ?>

            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4">

                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-red-600 shadow-sm">

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 9v2m0 4h.01M10.29 3.86l-8.82 15a2 2 0 001.73 3h17.6a2 2 0 001.73-3l-8.82-15a2 2 0 00-3.42 0z"
                        />

                    </svg>

                </div>


                <div>

                    <p class="font-semibold text-red-800">
                        Unable to update profile
                    </p>

                    <p class="mt-1 text-sm text-red-700">

                        <?= e($error) ?>

                    </p>

                </div>

            </div>

        <?php endif; ?>


        <!-- ACCOUNT INFORMATION -->

        <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

            <div class="mb-5">

                <h2 class="text-lg font-bold text-slate-900">
                    Account Information
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Details about your administrator account.
                </p>

            </div>


            <div class="grid gap-3 sm:grid-cols-2">


                <!-- ROLE -->

                <div class="rounded-2xl bg-slate-50 p-4">

                    <div class="flex items-center justify-between gap-3">

                        <div>

                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                                Role
                            </p>

                            <span class="mt-2 inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold <?= roleClasses($admin['role']) ?>">

                                <?= e(
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $admin['role']
                                        )
                                    )
                                ) ?>

                            </span>

                        </div>


                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-servora-700 shadow-sm">

                            <svg
                                class="h-5 w-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 15l2 2 4-4m5-1a9 9 0 11-18 0 9 9 0 0118 0z"
                                />

                            </svg>

                        </div>

                    </div>

                </div>


                <!-- STATUS -->

                <div class="rounded-2xl bg-slate-50 p-4">

                    <div class="flex items-center justify-between gap-3">

                        <div>

                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                                Account Status
                            </p>

                            <span class="mt-2 inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold <?= statusClasses($admin['status']) ?>">

                                <?= e(ucfirst($admin['status'])) ?>

                            </span>

                        </div>


                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-emerald-600 shadow-sm">

                            <svg
                                class="h-5 w-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.291 9 11.622C17.176 19.291 21 14.591 21 9c0-.984-.119-1.94-.344-2.856z"
                                />

                            </svg>

                        </div>

                    </div>

                </div>


                <!-- ADMIN ID -->

                <div class="rounded-2xl bg-slate-50 p-4">

                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                        Admin ID
                    </p>

                    <p class="mt-2 text-base font-bold text-slate-800">

                        #<?= (int) $admin['id'] ?>

                    </p>

                </div>


                <!-- CREATED -->

                <div class="rounded-2xl bg-slate-50 p-4">

                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                        Account Created
                    </p>

                    <p class="mt-2 text-base font-bold text-slate-800">

                        <?= e(
                            date(
                                'd M Y',
                                strtotime($admin['created_at'])
                            )
                        ) ?>

                    </p>

                </div>

            </div>

        </section>


        <!-- PERSONAL INFORMATION -->

        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-lg font-bold text-slate-900">
                    Personal Information
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Update the information associated with your administrator account.
                </p>

            </div>


            <form
                method="POST"
                class="p-5 sm:p-6"
            >

                <!-- CSRF -->

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >


                <div class="grid gap-5 sm:grid-cols-2">


                    <!-- FULL NAME -->

                    <div class="sm:col-span-2">

                        <label
                            for="full_name"
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >

                            Full Name

                        </label>


                        <div class="relative">

                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">

                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >

                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                    />

                                </svg>

                            </div>


                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                value="<?= e($admin['full_name']) ?>"
                                required
                                autocomplete="name"
                                class="min-h-[50px] w-full rounded-xl border border-slate-200 bg-white pl-12 pr-4 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-50"
                            >

                        </div>

                    </div>


                    <!-- EMAIL -->

                    <div>

                        <label
                            for="email"
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >

                            Email Address

                        </label>


                        <div class="relative">

                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">

                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >

                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                    />

                                </svg>

                            </div>


                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= e($admin['email']) ?>"
                                required
                                autocomplete="email"
                                class="min-h-[50px] w-full rounded-xl border border-slate-200 bg-white pl-12 pr-4 text-sm text-slate-800 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-50"
                            >

                        </div>

                    </div>


                    <!-- PHONE -->

                    <div>

                        <label
                            for="phone"
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >

                            Phone Number

                        </label>


                        <div class="relative">

                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">

                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >

                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.128a11.042 11.042 0 005.516 5.516l1.128-2.257a1 1 0 011.21-.502l4.493 1.498A1 1 0 0121 15.72V19a2 2 0 01-2 2h-1C9.163 21 3 14.837 3 7V5z"
                                    />

                                </svg>

                            </div>


                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?= e($admin['phone'] ?? '') ?>"
                                autocomplete="tel"
                                class="min-h-[50px] w-full rounded-xl border border-slate-200 bg-white pl-12 pr-4 text-sm text-slate-800 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-50"
                            >

                        </div>

                    </div>

                </div>


                <!-- ACTIONS -->

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">

                    <a
                        href="dashboard.php"
                        class="inline-flex min-h-[48px] items-center justify-center rounded-xl border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >

                        Cancel

                    </a>


                    <button
                        type="submit"
                        class="inline-flex min-h-[48px] items-center justify-center rounded-xl bg-servora-700 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-100"
                    >

                        <svg
                            class="mr-2 h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"
                            />

                        </svg>

                        Save Changes

                    </button>

                </div>

            </form>

        </section>


        <!-- SECURITY NOTICE -->

        <section class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 sm:p-5">

            <div class="flex items-start gap-3">

                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-servora-700 shadow-sm">

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v2h8z"
                        />

                    </svg>

                </div>


                <div>

                    <h3 class="text-sm font-bold text-slate-900">
                        Keep your account secure
                    </h3>

                    <p class="mt-1 text-sm leading-6 text-slate-600">

                        Your administrator account has access to sensitive Servora
                        operations. Keep your login credentials private and use a
                        strong password.

                    </p>

                </div>

            </div>

        </section>


    </main>


    <!-- FOOTER -->

    <footer class="border-t border-slate-200 bg-white">

        <div class="mx-auto max-w-5xl px-4 py-6 text-center text-xs text-slate-400 sm:px-6 lg:px-8">

            Servora Administrator Portal

        </div>

    </footer>


</div>

</body>

</html>