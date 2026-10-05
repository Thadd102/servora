<?php

// ============================================================
// ADMIN CLIENT MANAGEMENT
// View clients and activate/deactivate accounts.
// Global client access can also be locked/unlocked here.
// ============================================================

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// ------------------------------------------------------------
// CREATE CSRF TOKEN
// ------------------------------------------------------------

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

// ------------------------------------------------------------
// GET GLOBAL CLIENT ACCESS SETTING
// ------------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'client_login_enabled'
    LIMIT 1
");
$stmt->execute();

$clientLoginSetting = $stmt->fetchColumn();

// Default ON if the row does not exist.
$clientLoginEnabled = $clientLoginSetting === false
    ? true
    : ((string) $clientLoginSetting === "1");

// ------------------------------------------------------------
// GET ALL CLIENTS
// ------------------------------------------------------------

$stmt = $pdo->query("
    SELECT
        id,
        full_name,
        email,
        phone,
        status,
        created_at
    FROM users
    WHERE role = 'client'
    ORDER BY created_at DESC
");

$clients = $stmt->fetchAll();

$activeCount = 0;

foreach ($clients as $client) {
    if ($client["status"] === "active") {
        $activeCount++;
    }
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

    <title>Clients | Servora Admin</title>

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

<div class="mx-auto w-full max-w-7xl px-4 py-5 sm:px-6 lg:px-8">

    <!-- PAGE HEADER -->

    <header class="mb-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-servora-50 px-3 py-1 text-xs font-bold text-servora-700">

                    <span class="h-1.5 w-1.5 rounded-full bg-servora-600"></span>

                    Client Management

                </div>

                <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                    Clients
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    Manage client accounts and account status.
                </p>

            </div>

            <a
                href="dashboard.php"
                class="inline-flex min-h-[46px] items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm transition hover:border-servora-200 hover:bg-servora-50 hover:text-servora-700"
            >
                ← Admin Dashboard
            </a>

        </div>

    </header>

    <!-- GLOBAL CLIENT ACCESS -->

    <section
        class="mb-5 rounded-3xl border <?= $clientLoginEnabled ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' ?> p-5 shadow-sm"
    >

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <div class="flex items-center gap-2">

                    <span
                        class="h-3 w-3 rounded-full <?= $clientLoginEnabled ? 'bg-emerald-500' : 'bg-red-500' ?>"
                    ></span>

                    <h2
                        class="text-base font-black <?= $clientLoginEnabled ? 'text-emerald-900' : 'text-red-900' ?>"
                    >
                        Client Access:
                        <?= $clientLoginEnabled ? "ON" : "LOCKED" ?>
                    </h2>

                </div>

                <p
                    class="mt-1 text-sm <?= $clientLoginEnabled ? 'text-emerald-700' : 'text-red-700' ?>"
                >
                    <?php if ($clientLoginEnabled): ?>
                        Clients can currently access Servora normally.
                    <?php else: ?>
                        Client access is disabled. Existing client sessions will be ended on their next request.
                    <?php endif; ?>
                </p>

            </div>

            <form
                method="POST"
                action="toggle_client_access.php"
                onsubmit="return confirm('<?= $clientLoginEnabled
                    ? "Lock Servora for all clients? Existing client sessions will be ended on their next request."
                    : "Allow all active clients to access Servora again?"
                ?>');"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="<?= $clientLoginEnabled ? "lock" : "unlock" ?>"
                >

                <input
                    type="hidden"
                    name="redirect_to"
                    value="clients.php"
                >

                <?php if ($clientLoginEnabled): ?>

                    <button
                        type="submit"
                        class="inline-flex min-h-[46px] w-full items-center justify-center rounded-xl bg-red-600 px-5 text-sm font-black text-white transition hover:bg-red-700 sm:w-auto"
                    >
                        🔒 Lock All Clients
                    </button>

                <?php else: ?>

                    <button
                        type="submit"
                        class="inline-flex min-h-[46px] w-full items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-black text-white transition hover:bg-emerald-700 sm:w-auto"
                    >
                        ✓ Allow All Clients
                    </button>

                <?php endif; ?>

            </form>

        </div>

    </section>

    <!-- MESSAGES -->

    <?php if (($_GET["maintenance"] ?? "") === "locked"): ?>
        <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
            All client access has been locked.
        </div>
    <?php elseif (($_GET["maintenance"] ?? "") === "unlocked"): ?>
        <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-700">
            Client access has been restored.
        </div>
    <?php elseif (($_GET["maintenance"] ?? "") === "security"): ?>
        <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
            Security check failed. Please try again.
        </div>
    <?php endif; ?>

    <?php if (
        isset($_GET["status"])
        &&
        $_GET["status"] === "updated"
    ): ?>

        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 p-4">

            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-sm font-black text-emerald-700">
                ✓
            </div>

            <div>

                <p class="text-sm font-bold text-emerald-800">
                    Account updated successfully.
                </p>

                <p class="mt-0.5 text-xs text-emerald-700">
                    The client's account status has been updated.
                </p>

            </div>

        </div>

    <?php endif; ?>

    <!-- CLIENT SUMMARY -->

    <div class="mb-5 grid gap-3 sm:grid-cols-3">

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">
                        Total Clients
                    </p>

                    <p class="mt-1 text-2xl font-black text-slate-900">
                        <?= count($clients) ?>
                    </p>

                </div>

                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-servora-50 text-sm font-black text-servora-700">
                    <?= count($clients) ?>
                </div>

            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">
                        Active
                    </p>

                    <p class="mt-1 text-2xl font-black text-emerald-600">
                        <?= $activeCount ?>
                    </p>

                </div>

                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-50 text-sm font-black text-emerald-600">
                    ✓
                </div>

            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">
                        Inactive / Suspended
                    </p>

                    <p class="mt-1 text-2xl font-black text-red-600">
                        <?= count($clients) - $activeCount ?>
                    </p>

                </div>

                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-red-50 text-sm font-black text-red-600">
                    !
                </div>

            </div>

        </div>

    </div>

    <!-- MOBILE CLIENT CARDS -->

    <div class="space-y-3 lg:hidden">

        <?php if (empty($clients)): ?>

            <div class="rounded-3xl border border-slate-200 bg-white px-5 py-12 text-center shadow-sm">

                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-servora-50 text-servora-700">
                    —
                </div>

                <h2 class="mt-4 text-base font-black text-slate-900">
                    No clients found
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Registered client accounts will appear here.
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($clients as $client): ?>

                <?php

                $statusClass = match ($client["status"]) {
                    "active" => "border-emerald-100 bg-emerald-50 text-emerald-700",
                    "suspended" => "border-red-100 bg-red-50 text-red-700",
                    "inactive" => "border-slate-200 bg-slate-100 text-slate-600",
                    default => "border-slate-200 bg-slate-50 text-slate-600"
                };

                $initial = strtoupper(
                    substr(
                        $client["full_name"],
                        0,
                        1
                    )
                );

                ?>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">

                    <div class="flex items-start justify-between gap-3">

                        <div class="flex min-w-0 items-center gap-3">

                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-servora-50 text-sm font-black text-servora-700">
                                <?= htmlspecialchars($initial) ?>
                            </div>

                            <div class="min-w-0">

                                <h3 class="truncate text-sm font-black text-slate-900">
                                    <?= htmlspecialchars($client["full_name"]) ?>
                                </h3>

                                <p class="mt-0.5 truncate text-xs text-slate-500">
                                    <?= htmlspecialchars($client["email"]) ?>
                                </p>

                            </div>

                        </div>

                        <span class="shrink-0 rounded-full border px-3 py-1 text-[11px] font-bold <?= $statusClass ?>">
                            <?= htmlspecialchars(ucfirst($client["status"])) ?>
                        </span>

                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-3">

                        <div class="rounded-2xl bg-slate-50 p-3">

                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                Phone
                            </p>

                            <p class="mt-1 break-all text-xs font-semibold text-slate-700">
                                <?= htmlspecialchars($client["phone"]) ?>
                            </p>

                        </div>

                        <div class="rounded-2xl bg-slate-50 p-3">

                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                Registered
                            </p>

                            <p class="mt-1 text-xs font-semibold text-slate-700">
                                <?= htmlspecialchars($client["created_at"]) ?>
                            </p>

                        </div>

                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">

                        <a
                            href="view_client.php?id=<?= (int) $client["id"] ?>"
                            class="inline-flex min-h-[46px] items-center justify-center rounded-xl bg-servora-700 px-4 text-sm font-bold text-white transition hover:bg-servora-800"
                        >
                            View Dashboard
                        </a>

                        <form
                            method="POST"
                            action="toggle_client_status.php"
                            onsubmit="return confirm('Are you sure you want to change this client account status?');"
                        >

                            <input
                                type="hidden"
                                name="client_id"
                                value="<?= (int) $client["id"] ?>"
                            >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrfToken) ?>"
                            >

                            <?php if ($client["status"] === "active"): ?>

                                <button
                                    type="submit"
                                    class="inline-flex min-h-[46px] w-full items-center justify-center rounded-xl bg-red-50 px-4 text-sm font-bold text-red-600 transition hover:bg-red-100"
                                >
                                    Deactivate
                                </button>

                            <?php else: ?>

                                <button
                                    type="submit"
                                    class="inline-flex min-h-[46px] w-full items-center justify-center rounded-xl bg-emerald-50 px-4 text-sm font-bold text-emerald-600 transition hover:bg-emerald-100"
                                >
                                    Activate
                                </button>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <!-- DESKTOP CLIENT TABLE -->

    <div class="hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm lg:block">

        <?php if (empty($clients)): ?>

            <div class="px-5 py-16 text-center">

                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-servora-50 text-servora-700">
                    —
                </div>

                <h2 class="mt-4 text-base font-black text-slate-900">
                    No clients found
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Registered client accounts will appear here.
                </p>

            </div>

        <?php else: ?>

            <div class="overflow-x-auto">

                <table class="w-full min-w-[950px]">

                    <thead>

                    <tr class="border-b border-slate-100 bg-slate-50">

                        <th class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider text-slate-400">
                            Client
                        </th>

                        <th class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider text-slate-400">
                            Phone
                        </th>

                        <th class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider text-slate-400">
                            Status
                        </th>

                        <th class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider text-slate-400">
                            Registered
                        </th>

                        <th class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider text-slate-400">
                            Actions
                        </th>

                    </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100">

                    <?php foreach ($clients as $client): ?>

                        <?php

                        $statusClass = match ($client["status"]) {
                            "active" => "border-emerald-100 bg-emerald-50 text-emerald-700",
                            "suspended" => "border-red-100 bg-red-50 text-red-700",
                            "inactive" => "border-slate-200 bg-slate-100 text-slate-600",
                            default => "border-slate-200 bg-slate-50 text-slate-600"
                        };

                        $initial = strtoupper(
                            substr(
                                $client["full_name"],
                                0,
                                1
                            )
                        );

                        ?>

                        <tr class="transition hover:bg-slate-50">

                            <td class="px-6 py-5">

                                <div class="flex items-center gap-3">

                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-servora-50 text-sm font-black text-servora-700">
                                        <?= htmlspecialchars($initial) ?>
                                    </div>

                                    <div class="min-w-0">

                                        <p class="text-sm font-bold text-slate-900">
                                            <?= htmlspecialchars($client["full_name"]) ?>
                                        </p>

                                        <p class="mt-0.5 text-xs text-slate-500">
                                            <?= htmlspecialchars($client["email"]) ?>
                                        </p>

                                    </div>

                                </div>

                            </td>

                            <td class="px-6 py-5 text-sm font-semibold text-slate-600">
                                <?= htmlspecialchars($client["phone"]) ?>
                            </td>

                            <td class="px-6 py-5">

                                <span class="inline-flex rounded-full border px-3 py-1 text-xs font-bold <?= $statusClass ?>">
                                    <?= htmlspecialchars(ucfirst($client["status"])) ?>
                                </span>

                            </td>

                            <td class="px-6 py-5 text-sm font-semibold text-slate-600">
                                <?= htmlspecialchars($client["created_at"]) ?>
                            </td>

                            <td class="px-6 py-5">

                                <div class="flex flex-wrap items-center gap-2">

                                    <a
                                        href="view_client.php?id=<?= (int) $client["id"] ?>"
                                        class="inline-flex min-h-[40px] items-center justify-center rounded-xl bg-servora-700 px-3 text-xs font-bold text-white transition hover:bg-servora-800"
                                    >
                                        View Dashboard
                                    </a>

                                    <form
                                        method="POST"
                                        action="toggle_client_status.php"
                                        onsubmit="return confirm('Are you sure you want to change this client account status?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="client_id"
                                            value="<?= (int) $client["id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($csrfToken) ?>"
                                        >

                                        <?php if ($client["status"] === "active"): ?>

                                            <button
                                                type="submit"
                                                class="inline-flex min-h-[40px] items-center justify-center rounded-xl bg-red-50 px-3 text-xs font-bold text-red-600 transition hover:bg-red-100"
                                            >
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="submit"
                                                class="inline-flex min-h-[40px] items-center justify-center rounded-xl bg-emerald-50 px-3 text-xs font-bold text-emerald-600 transition hover:bg-emerald-100"
                                            >
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <footer class="py-7 text-center">

        <p class="text-xs text-slate-400">
            Servora Admin · Client Management
        </p>

    </footer>

</div>

</body>

</html>
