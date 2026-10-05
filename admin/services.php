
<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";


/*
|--------------------------------------------------------------------------
| Get all services
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        slug,
        description,
        image,
        regular_price,
        promo_price,
        promo_active,
        status,
        created_at,
        updated_at
    FROM services
    ORDER BY id DESC
");

$stmt->execute();

$services = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Services | Servora Admin</title>

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


<main class="mx-auto w-full max-w-7xl px-4 py-5 sm:px-6 sm:py-8 lg:px-8">


    <!-- Page Header -->

    <section
        class="mb-6 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-7"
    >

        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <a
                    href="dashboard.php"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-servora-700 transition hover:text-servora-800"
                >
                    <svg
                        class="h-4 w-4"
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


                <div class="mt-4">

                    <p class="text-xs font-bold uppercase tracking-widest text-servora-600">
                        Service Management
                    </p>

                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                        Manage Services
                    </h1>

                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        Create and manage the services available to your clients,
                        including pricing, promotions and service fields.
                    </p>

                </div>

            </div>


            <div class="flex flex-wrap items-center gap-2.5">
                <a
                    href="service_pricing.php"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    <svg class="h-4 w-4 text-servora-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Pricing & Margins
                </a>

                <a
                    href="utility_orders.php"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    <svg class="h-4 w-4 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Utility Orders
                </a>

                <a
                    href="add_service.php"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl bg-servora-700 px-5 py-3.5 text-sm font-bold text-white shadow-sm transition hover:bg-servora-800"
                >
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
                            d="M12 4v16m8-8H4"
                        />
                    </svg>
                    Add Service
                </a>
            </div>

        </div>

    </section>



    <?php if (empty($services)): ?>


        <!-- Empty State -->

        <section
            class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm"
        >

            <div
                class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-servora-50 text-servora-700"
            >

                <svg
                    class="h-8 w-8"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.7"
                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                    />
                </svg>

            </div>


            <h2 class="mt-5 text-lg font-black text-slate-900">
                No services yet
            </h2>

            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                You haven't created any services. Add your first service
                to make it available on the platform.
            </p>


            <a
                href="add_service.php"
                class="mt-6 inline-flex items-center justify-center rounded-2xl bg-servora-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-servora-800"
            >
                Add Your First Service
            </a>

        </section>


    <?php else: ?>


        <!-- Desktop / Tablet Table -->

        <section
            class="hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm lg:block"
        >

            <div class="border-b border-slate-100 px-6 py-5">

                <div class="flex items-center justify-between">

                    <div>

                        <h2 class="font-black text-slate-900">
                            All Services
                        </h2>

                        <p class="mt-1 text-xs text-slate-500">
                            <?= count($services) ?>
                            service<?= count($services) === 1 ? '' : 's' ?>
                            currently listed.
                        </p>

                    </div>

                </div>

            </div>


            <!-- MOBILE CARDS VIEW (Phones < 768px) -->
            <div class="block md:hidden divide-y divide-slate-100">
                <?php foreach ($services as $service): ?>
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <?php if (!empty($service["image"])): ?>
                                <img
                                    src="../uploads/services/<?= htmlspecialchars($service["image"]) ?>"
                                    alt="<?= htmlspecialchars($service["name"]) ?>"
                                    class="h-12 w-14 shrink-0 rounded-xl object-cover ring-1 ring-slate-200"
                                >
                            <?php else: ?>
                                <div class="flex h-12 w-14 shrink-0 items-center justify-center rounded-xl bg-servora-50 text-[10px] font-bold text-servora-600">
                                    No image
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <h3 class="font-bold text-slate-900 text-sm truncate"><?= htmlspecialchars($service["name"]) ?></h3>
                                <p class="text-xs text-slate-400 font-mono">/<?= htmlspecialchars($service["slug"]) ?></p>
                            </div>
                        </div>

                        <?php if ($service["status"] === "active"): ?>
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-700">
                                <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                Active
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-[10px] font-bold text-red-600">
                                <span class="h-1 w-1 rounded-full bg-red-500"></span>
                                Inactive
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($service["description"])): ?>
                        <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed">
                            <?= htmlspecialchars($service["description"]) ?>
                        </p>
                    <?php endif; ?>

                    <div class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 text-xs">
                        <span class="text-slate-500">Price:</span>
                        <div class="text-right">
                            <?php if ((int)$service["promo_active"] === 1 && $service["promo_price"] !== null && (float)$service["promo_price"] < (float)$service["regular_price"]): ?>
                                <span class="text-[10px] text-slate-400 line-through mr-1">₦<?= number_format((float)$service["regular_price"], 2) ?></span>
                                <span class="font-black text-servora-700">₦<?= number_format((float)$service["promo_price"], 2) ?></span>
                                <span class="ml-1 rounded-full bg-emerald-100 px-1.5 py-0.2 text-[9px] font-bold text-emerald-800">Promo</span>
                            <?php else: ?>
                                <span class="font-black text-slate-900">₦<?= number_format((float)$service["regular_price"], 2) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 pt-1">
                        <a href="edit_service.php?id=<?= (int)$service["id"] ?>"
                            class="rounded-xl bg-slate-900 py-2 text-center text-xs font-bold text-white hover:bg-slate-800 transition">
                            Edit
                        </a>
                        <a href="service_fields.php?id=<?= (int)$service["id"] ?>"
                            class="rounded-xl bg-servora-50 py-2 text-center text-xs font-bold text-servora-700 hover:bg-servora-100 transition">
                            Fields
                        </a>
                        <a href="edit_service_field.php?id=<?= (int)$service["id"] ?>"
                            class="rounded-xl border border-slate-200 bg-white py-2 text-center text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
                            Manage
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- DESKTOP TABLE VIEW (Screens >= 768px) -->
            <div class="hidden md:block overflow-x-auto">

                <table class="w-full min-w-[1100px] text-left">

                    <thead class="border-b border-slate-100 bg-slate-50">

                        <tr>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                Service
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                Description
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                Price
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                Promotion
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                Status
                            </th>

                            <th class="px-6 py-4 text-right text-xs font-bold uppercase tracking-wide text-slate-400">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100">


                        <?php foreach ($services as $service): ?>


                            <tr class="transition hover:bg-slate-50/70">


                                <!-- Service -->

                                <td class="px-6 py-5">

                                    <div class="flex items-center gap-4">


                                        <?php if (!empty($service["image"])): ?>

                                            <img
                                                src="../uploads/services/<?= htmlspecialchars($service["image"]) ?>"
                                                alt="<?= htmlspecialchars($service["name"]) ?>"
                                                class="h-14 w-16 rounded-xl object-cover ring-1 ring-slate-200"
                                            >

                                        <?php else: ?>

                                            <div
                                                class="flex h-14 w-16 shrink-0 items-center justify-center rounded-xl bg-servora-50 text-xs font-bold text-servora-600"
                                            >
                                                No image
                                            </div>

                                        <?php endif; ?>


                                        <div class="min-w-0">

                                            <p class="font-bold text-slate-900">
                                                <?= htmlspecialchars($service["name"]) ?>
                                            </p>

                                            <p class="mt-1 text-xs text-slate-400">
                                                /<?= htmlspecialchars($service["slug"]) ?>
                                            </p>

                                        </div>

                                    </div>

                                </td>



                                <!-- Description -->

                                <td class="max-w-xs px-6 py-5">

                                    <p class="line-clamp-2 text-sm leading-6 text-slate-500">

                                        <?= htmlspecialchars(
                                            $service["description"] ?? ""
                                        ) ?>

                                    </p>

                                </td>



                                <!-- Price -->

                                <td class="px-6 py-5">

                                    <?php if (
                                        (int) $service["promo_active"] === 1
                                        &&
                                        $service["promo_price"] !== null
                                        &&
                                        (float) $service["promo_price"]
                                            < (float) $service["regular_price"]
                                    ): ?>

                                        <p class="text-xs text-slate-400 line-through">
                                            ₦<?= number_format(
                                                (float) $service["regular_price"],
                                                2
                                            ) ?>
                                        </p>

                                        <p class="mt-1 font-black text-servora-700">
                                            ₦<?= number_format(
                                                (float) $service["promo_price"],
                                                2
                                            ) ?>
                                        </p>

                                    <?php else: ?>

                                        <p class="font-black text-slate-900">
                                            ₦<?= number_format(
                                                (float) $service["regular_price"],
                                                2
                                            ) ?>
                                        </p>

                                    <?php endif; ?>

                                </td>



                                <!-- Promotion -->

                                <td class="px-6 py-5">

                                    <?php if (
                                        (int) $service["promo_active"] === 1
                                        &&
                                        $service["promo_price"] !== null
                                    ): ?>

                                        <span
                                            class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                        >
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"
                                        >
                                            Off
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- Status -->

                                <td class="px-6 py-5">

                                    <?php if (
                                        $service["status"] === "active"
                                    ): ?>

                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                        >

                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>

                                            Active

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-600"
                                        >

                                            <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>

                                            Inactive

                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- Actions -->

                                <td class="px-6 py-5">

                                    <div class="flex flex-wrap justify-end gap-2">

                                        <a
                                            href="edit_service.php?id=<?= (int) $service["id"] ?>"
                                            class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                        >
                                            Edit Service
                                        </a>

                                        <a
                                            href="service_fields.php?id=<?= (int) $service["id"] ?>"
                                            class="inline-flex items-center justify-center rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700 transition hover:bg-servora-100"
                                        >
                                            Define Fields
                                        </a>

                                        <a
                                            href="edit_service_field.php?id=<?= (int) $service["id"] ?>"
                                            class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-600 transition hover:bg-slate-50"
                                        >
                                            Edit Fields
                                        </a>

                                    </div>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    </tbody>

                </table>

            </div>

        </section>



        <!-- Mobile Cards -->

        <section class="space-y-4 lg:hidden">


            <?php foreach ($services as $service): ?>


                <article
                    class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                >


                    <!-- Image -->

                    <?php if (!empty($service["image"])): ?>

                        <img
                            src="../uploads/services/<?= htmlspecialchars($service["image"]) ?>"
                            alt="<?= htmlspecialchars($service["name"]) ?>"
                            class="h-48 w-full object-cover"
                        >

                    <?php else: ?>

                        <div
                            class="flex h-40 w-full items-center justify-center bg-servora-50 text-sm font-semibold text-servora-600"
                        >
                            No service image
                        </div>

                    <?php endif; ?>


                    <div class="p-5">


                        <!-- Name + Status -->

                        <div class="flex items-start justify-between gap-3">

                            <div class="min-w-0">

                                <h2 class="text-lg font-black text-slate-900">
                                    <?= htmlspecialchars($service["name"]) ?>
                                </h2>

                                <p class="mt-1 text-xs text-slate-400">
                                    /<?= htmlspecialchars($service["slug"]) ?>
                                </p>

                            </div>


                            <?php if (
                                $service["status"] === "active"
                            ): ?>

                                <span
                                    class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700"
                                >
                                    Active
                                </span>

                            <?php else: ?>

                                <span
                                    class="shrink-0 rounded-full bg-red-50 px-2.5 py-1 text-[10px] font-bold text-red-600"
                                >
                                    Inactive
                                </span>

                            <?php endif; ?>

                        </div>



                        <!-- Description -->

                        <p class="mt-4 text-sm leading-6 text-slate-500">

                            <?= htmlspecialchars(
                                $service["description"] ?? ""
                            ) ?>

                        </p>



                        <!-- Price -->

                        <div class="mt-5 rounded-2xl bg-slate-50 p-4">

                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                Service Price
                            </p>


                            <?php if (
                                (int) $service["promo_active"] === 1
                                &&
                                $service["promo_price"] !== null
                                &&
                                (float) $service["promo_price"]
                                    < (float) $service["regular_price"]
                            ): ?>

                                <div class="mt-1 flex items-center gap-2">

                                    <span class="text-xs text-slate-400 line-through">
                                        ₦<?= number_format(
                                            (float) $service["regular_price"],
                                            2
                                        ) ?>
                                    </span>

                                    <span class="text-xl font-black text-servora-700">
                                        ₦<?= number_format(
                                            (float) $service["promo_price"],
                                            2
                                        ) ?>
                                    </span>

                                </div>

                            <?php else: ?>

                                <p class="mt-1 text-xl font-black text-slate-900">
                                    ₦<?= number_format(
                                        (float) $service["regular_price"],
                                        2
                                    ) ?>
                                </p>

                            <?php endif; ?>

                        </div>



                        <!-- Promotion -->

                        <div class="mt-3">

                            <?php if (
                                (int) $service["promo_active"] === 1
                                &&
                                $service["promo_price"] !== null
                            ): ?>

                                <span
                                    class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                >
                                    Promotion Active
                                </span>

                            <?php else: ?>

                                <span
                                    class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"
                                >
                                    No Promotion
                                </span>

                            <?php endif; ?>

                        </div>



                        <!-- Actions -->

                        <div class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-3">

                            <a
                                href="edit_service.php?id=<?= (int) $service["id"] ?>"
                                class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Edit Service
                            </a>

                            <a
                                href="service_fields.php?id=<?= (int) $service["id"] ?>"
                                class="inline-flex items-center justify-center rounded-xl bg-servora-50 px-4 py-3 text-sm font-bold text-servora-700 transition hover:bg-servora-100"
                            >
                                Define Fields
                            </a>

                            <a
                                href="edit_service_field.php?id=<?= (int) $service["id"] ?>"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
                                Edit Fields
                            </a>

                        </div>

                    </div>

                </article>


            <?php endforeach; ?>


        </section>


    <?php endif; ?>


    <!-- Footer -->

    <footer class="py-8 text-center text-xs text-slate-400">

        Servora Administration

    </footer>


</main>

</body>

</html>
```
