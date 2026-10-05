<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";


/*
|--------------------------------------------------------------------------
| CREATE CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

$success = "";
$error = "";


/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | VERIFY CSRF TOKEN
    |--------------------------------------------------------------------------
    */

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
        | ADD NETWORK
        |--------------------------------------------------------------------------
        */

        if ($action === "add_network") {

            $name = trim($_POST["name"] ?? "");
            $slug = strtolower(trim($_POST["slug"] ?? ""));

            /*
             * Keep slug URL/database friendly.
             *
             * Example:
             *
             * Airtel Nigeria -> airtel-nigeria
             */

            $slug = preg_replace(
                '/[^a-z0-9]+/',
                '-',
                $slug
            );

            $slug = trim(
                $slug,
                '-'
            );


            if ($name === "") {

                $error = "Please enter the network name.";

            } elseif ($slug === "") {

                $error = "Please enter a valid network slug.";

            } elseif (strlen($name) > 50) {

                $error = "Network name is too long.";

            } elseif (strlen($slug) > 50) {

                $error = "Network slug is too long.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK DUPLICATE NAME OR SLUG
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM networks
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
                            "A network with this name or slug already exists.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | INSERT NETWORK
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO networks (
                                name,
                                slug,
                                status
                            )
                            VALUES (
                                ?,
                                ?,
                                'active'
                            )
                        ");

                        $stmt->execute([
                            $name,
                            $slug
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | REDIRECT TO PREVENT FORM RESUBMISSION
                        |--------------------------------------------------------------------------
                        */

                        header(
                            "Location: data_networks.php?success=added"
                        );

                        exit;
                    }

                } catch (Throwable $e) {

                    error_log(
                        "Servora add network error: " .
                        $e->getMessage()
                    );

                    $error =
                        "The network could not be added.";
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CHANGE NETWORK STATUS
        |--------------------------------------------------------------------------
        */

        elseif ($action === "change_status") {

            $networkId =
                (int) ($_POST["network_id"] ?? 0);

            $newStatus =
                $_POST["new_status"] ?? "";


            if ($networkId <= 0) {

                $error = "Invalid network.";

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

                $error = "Invalid network status.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | MAKE SURE NETWORK EXISTS
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            name,
                            status
                        FROM networks
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $networkId
                    ]);

                    $network =
                        $stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$network) {

                        $error =
                            "Network was not found.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE NETWORK STATUS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE networks
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $newStatus,
                            $networkId
                        ]);


                        header(
                            "Location: data_networks.php?success=status"
                        );

                        exit;
                    }

                } catch (Throwable $e) {

                    error_log(
                        "Servora network status error: " .
                        $e->getMessage()
                    );

                    $error =
                        "Network status could not be updated.";
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
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

$successCode =
    $_GET["success"] ?? "";

if ($successCode === "added") {

    $success =
        "Network added successfully.";

} elseif ($successCode === "status") {

    $success =
        "Network status updated successfully.";
}


/*
|--------------------------------------------------------------------------
| GET NETWORKS AND PLAN COUNTS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        n.id,
        n.name,
        n.slug,
        n.status,
        n.created_at,

        COUNT(dp.id) AS total_plans,

        SUM(
            CASE
                WHEN dp.status = 'active'
                THEN 1
                ELSE 0
            END
        ) AS active_plans

    FROM networks n

    LEFT JOIN data_plans dp
        ON dp.network_id = n.id

    GROUP BY
        n.id,
        n.name,
        n.slug,
        n.status,
        n.created_at

    ORDER BY
        n.id ASC
");

$networks =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| NETWORK STATISTICS
|--------------------------------------------------------------------------
*/

$totalNetworks = count($networks);

$activeNetworks = 0;
$inactiveNetworks = 0;

foreach ($networks as $network) {

    if ($network["status"] === "active") {
        $activeNetworks++;
    } else {
        $inactiveNetworks++;
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

    <title>Networks | Subnext Admin</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

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
                    Subnext Data Management
                </p>

                <h1
                    class="mt-1 text-3xl font-black tracking-tight sm:text-4xl"
                >
                    Networks
                </h1>

                <p
                    class="mt-3 max-w-2xl text-sm leading-6 text-white/75 sm:text-base"
                >
                    Manage the mobile networks available for
                    data purchases on Subnext.
                </p>

            </div>

        </div>


        <div
            class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"
        ></div>

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
                Networks
            </p>

            <p
                class="mt-2 text-2xl font-black text-slate-900"
            >
                <?= $totalNetworks ?>
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
                <?= $activeNetworks ?>
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
                <?= $inactiveNetworks ?>
            </p>

        </div>

    </section>



    <!-- ADD NETWORK -->

    <section
        class="mt-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >

        <div>

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Add Network
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Create a new network
            </h2>

            <p
                class="mt-1 text-sm text-slate-500"
            >
                You only need this when Subnext starts
                supporting another mobile network.
            </p>

        </div>


        <form
            method="POST"
            class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-[1fr_1fr_auto]"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_network"
            >


            <!-- NAME -->

            <div>

                <label
                    for="name"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Network Name
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="50"
                    placeholder="Example: Airtel"
                    required
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <!-- SLUG -->

            <div>

                <label
                    for="slug"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Network Slug
                </label>

                <input
                    type="text"
                    id="slug"
                    name="slug"
                    maxlength="50"
                    placeholder="Example: airtel"
                    required
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <!-- BUTTON -->

            <div
                class="flex items-end"
            >

                <button
                    type="submit"
                    class="w-full rounded-xl bg-servora-700 px-6 py-3 text-sm font-bold text-white transition hover:bg-servora-800 lg:w-auto"
                >
                    Add Network
                </button>

            </div>

        </form>

    </section>



    <!-- NETWORK LIST -->

    <section class="mt-7">

        <div class="mb-4">

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Existing Networks
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Supported networks
            </h2>

        </div>


        <?php if (!$networks): ?>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"
            >

                <p
                    class="font-bold text-slate-700"
                >
                    No networks found
                </p>

            </div>

        <?php else: ?>


            <div
                class="grid gap-4 md:grid-cols-2"
            >


                <?php foreach ($networks as $network): ?>


                    <?php

                    $isActive =
                        $network["status"] ===
                        "active";

                    $totalPlans =
                        (int)
                        $network["total_plans"];

                    $activePlans =
                        (int)
                        $network["active_plans"];

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
                                    📡
                                </div>


                                <div>

                                    <h3
                                        class="text-lg font-black text-slate-900"
                                    >
                                        <?= htmlspecialchars($network["name"]) ?>
                                    </h3>

                                    <p
                                        class="mt-0.5 text-xs font-semibold text-slate-400"
                                    >
                                        <?= htmlspecialchars($network["slug"]) ?>
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



                        <!-- PLAN COUNTS -->

                        <div
                            class="mt-5 grid grid-cols-2 gap-3"
                        >

                            <div
                                class="rounded-xl bg-slate-50 p-3"
                            >

                                <p
                                    class="text-xs font-semibold text-slate-400"
                                >
                                    Total Plans
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
                                    class="text-xs font-semibold text-slate-400"
                                >
                                    Active Plans
                                </p>

                                <p
                                    class="mt-1 text-lg font-black text-servora-700"
                                >
                                    <?= $activePlans ?>
                                </p>

                            </div>

                        </div>



                        <!-- ACTIONS -->

                        <div
                            class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4"
                        >

                            <a
                                href="data_plans.php?network_id=<?= (int) $network["id"] ?>"
                                class="text-sm font-bold text-servora-600 transition hover:text-servora-800"
                            >
                                View Plans →
                            </a>


                            <form
                                method="POST"
                                onsubmit="return confirm('Are you sure you want to <?= $isActive ? "deactivate" : "activate" ?> this network?');"
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
                                    name="network_id"
                                    value="<?= (int) $network["id"] ?>"
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
        Subnext Network Management
    </footer>


</main>


<script>

/*
|--------------------------------------------------------------------------
| AUTO-GENERATE SLUG FROM NETWORK NAME
|--------------------------------------------------------------------------
|
| The admin can still manually edit the slug before submitting.
|
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