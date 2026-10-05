<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

$success = "";
$error = "";


/*
|--------------------------------------------------------------------------
| HANDLE ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        $submittedToken === "" ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $action = $_POST["action"] ?? "";


        /*
        |--------------------------------------------------------------------------
        | ADD PROVIDER
        |--------------------------------------------------------------------------
        */

        if ($action === "add_provider") {

            $name = trim($_POST["name"] ?? "");
            $slug = strtolower(trim($_POST["slug"] ?? ""));
            $baseUrl = trim($_POST["base_url"] ?? "");

            $slug = preg_replace(
                '/[^a-z0-9]+/',
                '-',
                $slug
            );

            $slug = trim($slug, "-");


            if ($name === "") {

                $error = "Please enter the provider name.";

            } elseif ($slug === "") {

                $error = "Please enter a valid provider slug.";

            } elseif (strlen($name) > 100) {

                $error = "Provider name is too long.";

            } elseif (strlen($slug) > 100) {

                $error = "Provider slug is too long.";

            } elseif (
                $baseUrl !== "" &&
                !filter_var($baseUrl, FILTER_VALIDATE_URL)
            ) {

                $error = "Please enter a valid provider base URL.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK DUPLICATE
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM api_providers
                        WHERE name = ?
                           OR slug = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $name,
                        $slug
                    ]);

                    if ($stmt->fetch()) {

                        $error =
                            "A provider with this name or slug already exists.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | INSERT PROVIDER
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO api_providers (
                                name,
                                slug,
                                base_url,
                                status
                            )
                            VALUES (
                                ?,
                                ?,
                                ?,
                                'active'
                            )
                        ");

                        $stmt->execute([
                            $name,
                            $slug,
                            $baseUrl !== "" ? $baseUrl : null
                        ]);

                        header(
                            "Location: data_providers.php?success=added"
                        );

                        exit;
                    }

                } catch (Throwable $e) {

                    error_log(
                        "Servora provider add error: " .
                        $e->getMessage()
                    );

                    $error =
                        "The provider could not be added.";
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CHANGE PROVIDER STATUS
        |--------------------------------------------------------------------------
        */

        elseif ($action === "change_status") {

            $providerId =
                (int) ($_POST["provider_id"] ?? 0);

            $newStatus =
                $_POST["new_status"] ?? "";


            if ($providerId <= 0) {

                $error = "Invalid provider.";

            } elseif (
                !in_array(
                    $newStatus,
                    [
                        "active",
                        "inactive"
                    ],
                    true
                )
            ) {

                $error = "Invalid provider status.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK PROVIDER
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            name,
                            status
                        FROM api_providers
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $providerId
                    ]);

                    $provider =
                        $stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$provider) {

                        $error =
                            "Provider was not found.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE STATUS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE api_providers
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $newStatus,
                            $providerId
                        ]);

                        header(
                            "Location: data_providers.php?success=status"
                        );

                        exit;
                    }

                } catch (Throwable $e) {

                    error_log(
                        "Servora provider status error: " .
                        $e->getMessage()
                    );

                    $error =
                        "Provider status could not be updated.";
                }
            }
        }

        else {

            $error = "Invalid action.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

$successCode =
    $_GET["success"] ?? "";

if ($successCode === "added") {

    $success =
        "API provider added successfully.";

} elseif ($successCode === "status") {

    $success =
        "Provider status updated successfully.";
}


/*
|--------------------------------------------------------------------------
| GET PROVIDERS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        ap.id,
        ap.name,
        ap.slug,
        ap.base_url,
        ap.status,
        ap.created_at,

        COUNT(DISTINCT dp.id) AS total_plans,

        COUNT(
            DISTINCT CASE
                WHEN dp.status = 'active'
                THEN dp.id
            END
        ) AS active_plans,

        COUNT(DISTINCT dpt.id) AS provider_transactions

    FROM api_providers ap

    LEFT JOIN data_plans dp
        ON dp.provider_id = ap.id

    LEFT JOIN data_provider_transactions dpt
        ON dpt.provider_id = ap.id

    GROUP BY
        ap.id,
        ap.name,
        ap.slug,
        ap.base_url,
        ap.status,
        ap.created_at

    ORDER BY
        ap.id ASC
");

$providers =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| PROVIDER STATISTICS
|--------------------------------------------------------------------------
*/

$totalProviders = count($providers);

$activeProviders = 0;
$inactiveProviders = 0;

foreach ($providers as $provider) {

    if ($provider["status"] === "active") {
        $activeProviders++;
    } else {
        $inactiveProviders++;
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

    <title>API Providers | Servora Admin</title>

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

<main
    class="mx-auto w-full max-w-7xl px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>


    <!-- HEADER -->

    <section
        class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 text-white shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <a
                href="data_control.php"
                class="inline-flex items-center text-sm font-semibold text-white/75 transition hover:text-white"
            >
                ← Data Control
            </a>


            <div class="mt-5">

                <p class="text-sm font-semibold text-white/70">
                    Servora Data Management
                </p>

                <h1
                    class="mt-1 text-3xl font-black tracking-tight sm:text-4xl"
                >
                    API Providers
                </h1>

                <p
                    class="mt-3 max-w-2xl text-sm leading-6 text-white/75 sm:text-base"
                >
                    Manage the suppliers that Servora uses to
                    process customer data purchases.
                </p>

            </div>

        </div>


        <div
            class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"
        ></div>

    </section>



    <!-- SECURITY NOTICE -->

    <section
        class="mt-5 rounded-2xl border border-blue-200 bg-blue-50 p-4"
    >

        <div class="flex gap-3">

            <div class="text-xl">
                🔐
            </div>

            <div>

                <p
                    class="font-bold text-blue-900"
                >
                    Provider credentials are protected
                </p>

                <p
                    class="mt-1 text-sm leading-6 text-blue-700"
                >
                    API keys and secret credentials are not managed
                    on this page. Keep supplier credentials in your
                    server environment configuration.
                </p>

            </div>

        </div>

    </section>



    <!-- MESSAGES -->

    <?php if ($success !== ""): ?>

        <div
            class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700"
        >
            <?= htmlspecialchars($success) ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div
            class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700"
        >
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>



    <!-- STATISTICS -->

    <section
        class="mt-6 grid grid-cols-3 gap-3"
    >

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Providers
            </p>

            <p
                class="mt-2 text-2xl font-black"
            >
                <?= $totalProviders ?>
            </p>

        </div>


        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Active
            </p>

            <p
                class="mt-2 text-2xl font-black text-emerald-600"
            >
                <?= $activeProviders ?>
            </p>

        </div>


        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Inactive
            </p>

            <p
                class="mt-2 text-2xl font-black text-slate-500"
            >
                <?= $inactiveProviders ?>
            </p>

        </div>

    </section>



    <!-- ADD PROVIDER -->

    <section
        class="mt-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >

        <div>

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Add Provider
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Register a data supplier
            </h2>

            <p
                class="mt-1 text-sm leading-6 text-slate-500"
            >
                Add the supplier record here. API credentials
                remain outside the database.
            </p>

        </div>


        <form
            method="POST"
            class="mt-5 grid gap-4 lg:grid-cols-3"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_provider"
            >


            <!-- PROVIDER NAME -->

            <div>

                <label
                    for="name"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Provider Name
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    placeholder="Example: CheapDataHub"
                    required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <!-- SLUG -->

            <div>

                <label
                    for="slug"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Provider Slug
                </label>

                <input
                    type="text"
                    id="slug"
                    name="slug"
                    maxlength="100"
                    placeholder="Example: cheapdatahub"
                    required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <!-- BASE URL -->

            <div>

                <label
                    for="base_url"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Base URL
                </label>

                <input
                    type="url"
                    id="base_url"
                    name="base_url"
                    maxlength="255"
                    placeholder="https://provider.example/api/"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <div class="lg:col-span-3">

                <button
                    type="submit"
                    class="rounded-xl bg-servora-700 px-6 py-3 text-sm font-bold text-white transition hover:bg-servora-800"
                >
                    Add Provider
                </button>

            </div>

        </form>

    </section>



    <!-- PROVIDER LIST -->

    <section class="mt-7">

        <div class="mb-4">

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Suppliers
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Registered API providers
            </h2>

        </div>


        <?php if (!$providers): ?>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"
            >

                <p class="font-bold text-slate-700">
                    No providers registered
                </p>

            </div>

        <?php else: ?>


            <div
                class="grid gap-4 md:grid-cols-2"
            >


                <?php foreach ($providers as $provider): ?>

                    <?php

                    $isActive =
                        $provider["status"] === "active";

                    $totalPlans =
                        (int) $provider["total_plans"];

                    $activePlans =
                        (int) $provider["active_plans"];

                    $providerTransactions =
                        (int) $provider["provider_transactions"];

                    ?>


                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >

                        <div
                            class="flex items-start justify-between gap-4"
                        >

                            <div
                                class="flex items-center gap-3"
                            >

                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-xl bg-servora-50 text-xl"
                                >
                                    🔌
                                </div>


                                <div>

                                    <h3
                                        class="text-lg font-black text-slate-900"
                                    >
                                        <?= htmlspecialchars($provider["name"]) ?>
                                    </h3>

                                    <p
                                        class="mt-0.5 text-xs font-semibold text-slate-400"
                                    >
                                        <?= htmlspecialchars($provider["slug"]) ?>
                                    </p>

                                </div>

                            </div>


                            <?php if ($isActive): ?>

                                <span
                                    class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                >
                                    Active
                                </span>

                            <?php else: ?>

                                <span
                                    class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"
                                >
                                    Inactive
                                </span>

                            <?php endif; ?>

                        </div>



                        <!-- BASE URL -->

                        <div
                            class="mt-5 rounded-xl bg-slate-50 p-3"
                        >

                            <p
                                class="text-xs font-semibold text-slate-400"
                            >
                                API Base URL
                            </p>


                            <?php if (!empty($provider["base_url"])): ?>

                                <p
                                    class="mt-1 break-all text-sm font-semibold text-slate-700"
                                >
                                    <?= htmlspecialchars($provider["base_url"]) ?>
                                </p>

                            <?php else: ?>

                                <p
                                    class="mt-1 text-sm font-semibold text-slate-400"
                                >
                                    Not configured
                                </p>

                            <?php endif; ?>

                        </div>



                        <!-- STATISTICS -->

                        <div
                            class="mt-4 grid grid-cols-3 gap-3"
                        >

                            <div
                                class="rounded-xl bg-slate-50 p-3"
                            >

                                <p
                                    class="text-xs text-slate-400"
                                >
                                    Plans
                                </p>

                                <p
                                    class="mt-1 text-lg font-black"
                                >
                                    <?= $totalPlans ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-3"
                            >

                                <p
                                    class="text-xs text-slate-400"
                                >
                                    Active Plans
                                </p>

                                <p
                                    class="mt-1 text-lg font-black text-servora-700"
                                >
                                    <?= $activePlans ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-3"
                            >

                                <p
                                    class="text-xs text-slate-400"
                                >
                                    API Calls
                                </p>

                                <p
                                    class="mt-1 text-lg font-black"
                                >
                                    <?= $providerTransactions ?>
                                </p>

                            </div>

                        </div>



                        <!-- ACTIONS -->

                        <div
                            class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between"
                        >

                            <a
                                href="data_plans.php?provider_id=<?= (int) $provider["id"] ?>"
                                class="text-sm font-bold text-servora-600 transition hover:text-servora-800"
                            >
                                View Provider Plans →
                            </a>


                            <form
                                method="POST"
                                onsubmit="return confirm('Are you sure you want to <?= $isActive ? "deactivate" : "activate" ?> this provider?');"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="change_status"
                                >

                                <input
                                    type="hidden"
                                    name="provider_id"
                                    value="<?= (int) $provider["id"] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="new_status"
                                    value="<?= $isActive ? "inactive" : "active" ?>"
                                >


                                <?php if ($isActive): ?>

                                    <button
                                        type="submit"
                                        class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100"
                                    >
                                        Deactivate
                                    </button>

                                <?php else: ?>

                                    <button
                                        type="submit"
                                        class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100"
                                    >
                                        Activate
                                    </button>

                                <?php endif; ?>

                            </form>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>



    <!-- NAVIGATION -->

    <section
        class="mt-8 border-t border-slate-200 pt-6"
    >

        <div
            class="flex flex-col gap-3 sm:flex-row"
        >

            <a
                href="data_control.php"
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
            >
                ← Data Control
            </a>


            <a
                href="data_plans.php"
                class="inline-flex items-center justify-center rounded-xl bg-servora-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-servora-800"
            >
                Manage Data Plans
            </a>

        </div>

    </section>


    <footer
        class="py-8 text-center text-xs text-slate-400"
    >
        Servora API Provider Management
    </footer>


</main>


<script>

/*
|--------------------------------------------------------------------------
| AUTO GENERATE PROVIDER SLUG
|--------------------------------------------------------------------------
*/

const nameInput =
    document.getElementById("name");

const slugInput =
    document.getElementById("slug");

let slugEditedManually = false;


slugInput.addEventListener(
    "input",
    function () {

        slugEditedManually = true;

    }
);


nameInput.addEventListener(
    "input",
    function () {

        if (slugEditedManually) {
            return;
        }

        slugInput.value =
            nameInput.value
                .toLowerCase()
                .trim()
                .replace(
                    /[^a-z0-9]+/g,
                    "-"
                )
                .replace(
                    /^-+|-+$/g,
                    ""
                );

    }
);

</script>


</body>

</html>