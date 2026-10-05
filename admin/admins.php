<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

/*
|--------------------------------------------------------------------------
| SUPER ADMIN ONLY
|--------------------------------------------------------------------------
*/

if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die("Access denied. Only the Super Admin can manage administrators.");
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| FETCH ADMINS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        id,
        full_name,
        email,
        phone,
        role,
        status,
        created_at
    FROM users
    WHERE role IN ('admin', 'super_admin')
    ORDER BY created_at DESC
");

$admins = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
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

function roleLabel(string $role): string
{
    return $role === 'super_admin'
        ? 'Super Admin'
        : 'Admin';
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

    <title>Admin Management - Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-800">


<!-- PAGE -->

<div class="min-h-screen">


    <!-- TOP HEADER -->

    <header class="border-b border-slate-200 bg-white">

        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">

            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">

                <div>

                    <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-servora-50 px-3 py-1.5 text-xs font-semibold text-servora-700">

                        <span class="h-2 w-2 rounded-full bg-servora-600"></span>

                        Super Admin

                    </div>


                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">

                        Admin Management

                    </h1>


                    <p class="mt-1 max-w-xl text-sm leading-6 text-slate-500">

                        Manage Subnext administrators, roles and account access.

                    </p>

                </div>


                <!-- ACTIONS -->

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">

                    <a
                        href="add_admin.php"
                        class="inline-flex min-h-[46px] items-center justify-center rounded-xl bg-servora-700 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-100"
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
                                d="M12 4v16m8-8H4"
                            />

                        </svg>

                        Add Admin

                    </a>


                    <a
                        href="dashboard.php"
                        class="inline-flex min-h-[46px] items-center justify-center rounded-xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
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

        </div>

    </header>


    <!-- MAIN -->

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">


        <!-- SUCCESS MESSAGES -->

        <?php if (isset($_GET['created'])): ?>

            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">

                <div class="mt-0.5 shrink-0">

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

                    <p class="font-semibold">
                        Admin account created
                    </p>

                    <p class="mt-1 text-sm text-emerald-700">
                        The new administrator account was created successfully.
                    </p>

                </div>

            </div>

        <?php endif; ?>


        <?php if (isset($_GET['updated'])): ?>

            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">

                <div class="mt-0.5 shrink-0">

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

                    <p class="font-semibold">
                        Admin account updated
                    </p>

                    <p class="mt-1 text-sm text-emerald-700">
                        Administrator information was updated successfully.
                    </p>

                </div>

            </div>

        <?php endif; ?>


        <?php if (isset($_GET['status_updated'])): ?>

            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">

                <div class="mt-0.5 shrink-0">

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

                    <p class="font-semibold">
                        Admin status updated
                    </p>

                    <p class="mt-1 text-sm text-emerald-700">
                        The administrator's access status has been updated.
                    </p>

                </div>

            </div>

        <?php endif; ?>


        <!-- SUMMARY -->

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3">

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                    Total Admins
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-900">
                    <?= count($admins) ?>
                </p>

            </div>


            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                    Active
                </p>

                <p class="mt-2 text-2xl font-bold text-emerald-600">

                    <?php

                    $activeCount = 0;

                    foreach ($admins as $admin) {

                        if ($admin['status'] === 'active') {
                            $activeCount++;
                        }

                    }

                    echo $activeCount;

                    ?>

                </p>

            </div>


            <div class="col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:col-span-1">

                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                    Super Admins
                </p>

                <p class="mt-2 text-2xl font-bold text-amber-600">

                    <?php

                    $superAdminCount = 0;

                    foreach ($admins as $admin) {

                        if ($admin['role'] === 'super_admin') {
                            $superAdminCount++;
                        }

                    }

                    echo $superAdminCount;

                    ?>

                </p>

            </div>

        </div>


        <!-- ADMIN LIST -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">


            <!-- SECTION HEADER -->

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <h2 class="text-lg font-bold text-slate-900">
                            Administrators
                        </h2>

                        <p class="text-sm text-slate-500">
                            Review administrator accounts and manage access.
                        </p>

                    </div>


                    <div class="text-sm text-slate-400">

                        <?= count($admins) ?>

                        <?= count($admins) === 1 ? 'administrator' : 'administrators' ?>

                    </div>

                </div>

            </div>


            <?php if (empty($admins)): ?>


                <!-- EMPTY STATE -->

                <div class="px-6 py-16 text-center">

                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-servora-50 text-servora-700">

                        <svg
                            class="h-8 w-8"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"
                            />

                        </svg>

                    </div>


                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        No administrators found
                    </h3>


                    <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-500">

                        There are currently no administrator accounts available.

                    </p>


                    <a
                        href="add_admin.php"
                        class="mt-6 inline-flex min-h-[46px] items-center justify-center rounded-xl bg-servora-700 px-5 text-sm font-semibold text-white hover:bg-servora-800"
                    >

                        Add Administrator

                    </a>

                </div>


            <?php else: ?>


                <!-- MOBILE CARDS -->

                <div class="divide-y divide-slate-100 lg:hidden">

                    <?php foreach ($admins as $admin): ?>

                        <div class="p-5">

                            <div class="flex items-start gap-4">


                                <!-- AVATAR -->

                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-servora-100 text-lg font-bold text-servora-700">

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


                                <div class="min-w-0 flex-1">

                                    <div class="flex flex-wrap items-center gap-2">

                                        <h3 class="truncate font-bold text-slate-900">

                                            <?= e($admin['full_name']) ?>

                                        </h3>


                                        <?php if ($admin['id'] == $_SESSION['user_id']): ?>

                                            <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-500">

                                                You

                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <p class="mt-1 break-all text-sm text-slate-500">

                                        <?= e($admin['email']) ?>

                                    </p>

                                </div>

                            </div>


                            <!-- INFO -->

                            <div class="mt-4 grid grid-cols-2 gap-3">

                                <div class="rounded-xl bg-slate-50 p-3">

                                    <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                        Role
                                    </p>

                                    <span class="mt-1 inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold <?= roleClasses($admin['role']) ?>">

                                        <?= e(roleLabel($admin['role'])) ?>

                                    </span>

                                </div>


                                <div class="rounded-xl bg-slate-50 p-3">

                                    <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                        Status
                                    </p>

                                    <span class="mt-1 inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold <?= statusClasses($admin['status']) ?>">

                                        <?= e(ucfirst($admin['status'])) ?>

                                    </span>

                                </div>


                                <div class="rounded-xl bg-slate-50 p-3">

                                    <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                        Phone
                                    </p>

                                    <p class="mt-1 truncate text-sm font-medium text-slate-700">

                                        <?= e($admin['phone'] ?? '-') ?>

                                    </p>

                                </div>


                                <div class="rounded-xl bg-slate-50 p-3">

                                    <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                        Created
                                    </p>

                                    <p class="mt-1 text-sm font-medium text-slate-700">

                                        <?= date(
                                            'd M Y',
                                            strtotime($admin['created_at'])
                                        ) ?>

                                    </p>

                                </div>

                            </div>


                            <!-- ACTIONS -->

                            <div class="mt-4 flex flex-col gap-2 sm:flex-row">

                                <a
                                    href="edit_admin.php?id=<?= (int) $admin['id'] ?>"
                                    class="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl bg-servora-700 px-4 text-sm font-semibold text-white hover:bg-servora-800"
                                >

                                    Edit Admin

                                </a>


                                <?php if (
                                    (int) $admin['id']
                                    !==
                                    (int) $_SESSION['user_id']
                                ): ?>

                                    <form
                                        method="POST"
                                        action="toggle_admin_status.php"
                                        class="flex-1"
                                        onsubmit="return confirm('Are you sure you want to change this admin status?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $admin['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >


                                        <?php if ($admin['status'] === 'active'): ?>

                                            <button
                                                type="submit"
                                                class="min-h-[44px] w-full rounded-xl border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                                            >

                                                Suspend

                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="submit"
                                                class="min-h-[44px] w-full rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100"
                                            >

                                                Activate

                                            </button>

                                        <?php endif; ?>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>


                <!-- DESKTOP TABLE -->

                <div class="hidden overflow-x-auto lg:block">

                    <table class="w-full min-w-[1000px] text-left">

                        <thead class="border-b border-slate-200 bg-slate-50">

                            <tr>

                                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Admin
                                </th>

                                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Phone
                                </th>

                                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Role
                                </th>

                                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Status
                                </th>

                                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Created
                                </th>

                                <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Actions
                                </th>

                            </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">

                            <?php foreach ($admins as $admin): ?>

                                <tr class="transition hover:bg-slate-50">


                                    <!-- ADMIN -->

                                    <td class="px-6 py-5">

                                        <div class="flex items-center gap-3">

                                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700">

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

                                                <div class="flex items-center gap-2">

                                                    <p class="font-semibold text-slate-900">

                                                        <?= e($admin['full_name']) ?>

                                                    </p>


                                                    <?php if (
                                                        (int) $admin['id']
                                                        ===
                                                        (int) $_SESSION['user_id']
                                                    ): ?>

                                                        <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-500">

                                                            You

                                                        </span>

                                                    <?php endif; ?>

                                                </div>


                                                <p class="mt-0.5 text-sm text-slate-500">

                                                    <?= e($admin['email']) ?>

                                                </p>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- PHONE -->

                                    <td class="px-6 py-5 text-sm text-slate-600">

                                        <?= e($admin['phone'] ?? '-') ?>

                                    </td>


                                    <!-- ROLE -->

                                    <td class="px-6 py-5">

                                        <span class="inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold <?= roleClasses($admin['role']) ?>">

                                            <?= e(roleLabel($admin['role'])) ?>

                                        </span>

                                    </td>


                                    <!-- STATUS -->

                                    <td class="px-6 py-5">

                                        <span class="inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold <?= statusClasses($admin['status']) ?>">

                                            <?= e(ucfirst($admin['status'])) ?>

                                        </span>

                                    </td>


                                    <!-- CREATED -->

                                    <td class="px-6 py-5 text-sm text-slate-600">

                                        <?= date(
                                            'd M Y',
                                            strtotime($admin['created_at'])
                                        ) ?>

                                    </td>


                                    <!-- ACTIONS -->

                                    <td class="px-6 py-5">

                                        <div class="flex justify-end gap-2">


                                            <!-- EDIT -->

                                            <a
                                                href="edit_admin.php?id=<?= (int) $admin['id'] ?>"
                                                class="inline-flex min-h-[40px] items-center justify-center rounded-xl bg-servora-50 px-4 text-sm font-semibold text-servora-700 transition hover:bg-servora-100"
                                            >

                                                Edit

                                            </a>


                                            <!-- STATUS -->

                                            <?php if (
                                                (int) $admin['id']
                                                !==
                                                (int) $_SESSION['user_id']
                                            ): ?>

                                                <form
                                                    method="POST"
                                                    action="toggle_admin_status.php"
                                                    onsubmit="return confirm('Are you sure you want to change this admin status?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int) $admin['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($csrfToken) ?>"
                                                    >


                                                    <?php if ($admin['status'] === 'active'): ?>

                                                        <button
                                                            type="submit"
                                                            class="inline-flex min-h-[40px] items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                                                        >

                                                            Suspend

                                                        </button>

                                                    <?php else: ?>

                                                        <button
                                                            type="submit"
                                                            class="inline-flex min-h-[40px] items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100"
                                                        >

                                                            Activate

                                                        </button>

                                                    <?php endif; ?>

                                                </form>

                                            <?php else: ?>

                                                <span class="inline-flex min-h-[40px] items-center rounded-xl bg-slate-100 px-4 text-xs font-semibold text-slate-500">

                                                    Current Account

                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


            <?php endif; ?>

        </section>


        <!-- SECURITY NOTE -->

        <div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 sm:p-5">

            <div class="flex items-start gap-3">

                <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-servora-700 shadow-sm">

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
                        Administrator security
                    </h3>

                    <p class="mt-1 text-sm leading-6 text-slate-600">

                        Only the Super Admin can manage administrator accounts.
                        Your current account cannot be suspended from this page.

                    </p>

                </div>

            </div>

        </div>


    </main>


    <!-- FOOTER -->

    <footer class="border-t border-slate-200 bg-white">

        <div class="mx-auto max-w-7xl px-4 py-6 text-center text-xs text-slate-400 sm:px-6 lg:px-8">

            Subnext Admin Management

        </div>

    </footer>

</div>

</body>

</html>