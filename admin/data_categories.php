<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
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
        | ADD CATEGORY
        |--------------------------------------------------------------------------
        */

        if ($action === "add_category") {

            $name = trim($_POST["name"] ?? "");
            $slug = strtolower(trim($_POST["slug"] ?? ""));

            /*
             * Convert slug into a safe format.
             *
             * Example:
             * Router / Heavy Data
             *
             * becomes:
             * router-heavy-data
             */

            $slug = preg_replace(
                '/[^a-z0-9]+/',
                '-',
                $slug
            );

            $slug = trim($slug, "-");


            if ($name === "") {

                $error = "Please enter the category name.";

            } elseif ($slug === "") {

                $error = "Please enter a valid category slug.";

            } elseif (strlen($name) > 100) {

                $error = "Category name is too long.";

            } elseif (strlen($slug) > 100) {

                $error = "Category slug is too long.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK DUPLICATE
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM data_categories
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
                            "A category with this name or slug already exists.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | INSERT CATEGORY
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO data_categories (
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


                        header(
                            "Location: data_categories.php?success=added"
                        );

                        exit;
                    }

                } catch (Throwable $e) {

                    error_log(
                        "Subnext add data category error: " .
                        $e->getMessage()
                    );

                    $error =
                        "The category could not be added.";
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CHANGE CATEGORY STATUS
        |--------------------------------------------------------------------------
        */

        elseif ($action === "change_status") {

            $categoryId =
                (int) ($_POST["category_id"] ?? 0);

            $newStatus =
                $_POST["new_status"] ?? "";


            if ($categoryId <= 0) {

                $error = "Invalid category.";

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

                $error = "Invalid category status.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK CATEGORY EXISTS
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            name,
                            status
                        FROM data_categories
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $categoryId
                    ]);

                    $category =
                        $stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$category) {

                        $error =
                            "Category was not found.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE STATUS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE data_categories
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $newStatus,
                            $categoryId
                        ]);


                        header(
                            "Location: data_categories.php?success=status"
                        );

                        exit;
                    }

                } catch (Throwable $e) {

                    error_log(
                        "Subnext category status error: " .
                        $e->getMessage()
                    );

                    $error =
                        "Category status could not be updated.";
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

$successCode = $_GET["success"] ?? "";

if ($successCode === "added") {

    $success =
        "Data category added successfully.";

} elseif ($successCode === "status") {

    $success =
        "Category status updated successfully.";
}


/*
|--------------------------------------------------------------------------
| GET CATEGORIES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        dc.id,
        dc.name,
        dc.slug,
        dc.status,
        dc.created_at,

        COUNT(dp.id) AS total_plans,

        SUM(
            CASE
                WHEN dp.status = 'active'
                THEN 1
                ELSE 0
            END
        ) AS active_plans

    FROM data_categories dc

    LEFT JOIN data_plans dp
        ON dp.category_id = dc.id

    GROUP BY
        dc.id,
        dc.name,
        dc.slug,
        dc.status,
        dc.created_at

    ORDER BY
        dc.id ASC
");

$categories =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| CATEGORY STATISTICS
|--------------------------------------------------------------------------
*/

$totalCategories = count($categories);

$activeCategories = 0;
$inactiveCategories = 0;

$totalCategorizedPlans = 0;

foreach ($categories as $category) {

    if ($category["status"] === "active") {

        $activeCategories++;

    } else {

        $inactiveCategories++;
    }

    $totalCategorizedPlans +=
        (int) $category["total_plans"];
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

    <title>Data Categories | Subnext Admin</title>

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

                <p
                    class="text-sm font-semibold text-white/70"
                >
                    Subnext Data Management
                </p>

                <h1
                    class="mt-1 text-3xl font-black tracking-tight sm:text-4xl"
                >
                    Data Categories
                </h1>

                <p
                    class="mt-3 max-w-2xl text-sm leading-6 text-white/75 sm:text-base"
                >
                    Organize your data plans into categories such as
                    Everyday, Monthly, Router / Heavy Data and SME.
                </p>

            </div>

        </div>


        <div
            class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"
        ></div>

    </section>



    <!-- INFORMATION -->

    <section
        class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4"
    >

        <div class="flex gap-3">

            <div class="text-xl">
                💡
            </div>

            <div>

                <p
                    class="font-bold text-amber-900"
                >
                    Categories organize plans
                </p>

                <p
                    class="mt-1 text-sm leading-6 text-amber-700"
                >
                    Deactivating a category does not delete its
                    existing plans or transaction history.
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
        class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4"
    >

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Categories
            </p>

            <p
                class="mt-2 text-2xl font-black"
            >
                <?= $totalCategories ?>
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
                <?= $activeCategories ?>
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
                <?= $inactiveCategories ?>
            </p>

        </div>


        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Categorized Plans
            </p>

            <p
                class="mt-2 text-2xl font-black text-servora-700"
            >
                <?= $totalCategorizedPlans ?>
            </p>

        </div>

    </section>



    <!-- ADD CATEGORY -->

    <section
        class="mt-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >

        <div>

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Add Category
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Create a data category
            </h2>

            <p
                class="mt-1 text-sm leading-6 text-slate-500"
            >
                Categories make it easier for customers and
                administrators to organize data plans.
            </p>

        </div>


        <form
            method="POST"
            class="mt-5 grid gap-4 md:grid-cols-[1fr_1fr_auto]"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_category"
            >


            <!-- NAME -->

            <div>

                <label
                    for="name"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Category Name
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    placeholder="Example: Monthly"
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
                    Category Slug
                </label>

                <input
                    type="text"
                    id="slug"
                    name="slug"
                    maxlength="100"
                    placeholder="Example: monthly"
                    required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <!-- BUTTON -->

            <div
                class="flex items-end"
            >

                <button
                    type="submit"
                    class="w-full rounded-xl bg-servora-700 px-6 py-3 text-sm font-bold text-white transition hover:bg-servora-800 md:w-auto"
                >
                    Add Category
                </button>

            </div>

        </form>

    </section>



    <!-- CATEGORY LIST -->

    <section class="mt-7">

        <div class="mb-4">

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Existing Categories
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Data plan categories
            </h2>

        </div>


        <?php if (!$categories): ?>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"
            >

                <p
                    class="font-bold text-slate-700"
                >
                    No data categories found
                </p>

            </div>

        <?php else: ?>


            <div
                class="grid gap-4 md:grid-cols-2"
            >


                <?php foreach ($categories as $category): ?>

                    <?php

                    $isActive =
                        $category["status"] === "active";

                    $totalPlans =
                        (int) $category["total_plans"];

                    $activePlans =
                        (int) $category["active_plans"];

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
                                    🗂️
                                </div>


                                <div>

                                    <h3
                                        class="text-lg font-black text-slate-900"
                                    >
                                        <?= htmlspecialchars($category["name"]) ?>
                                    </h3>

                                    <p
                                        class="mt-0.5 text-xs font-semibold text-slate-400"
                                    >
                                        <?= htmlspecialchars($category["slug"]) ?>
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
                                href="data_plans.php?category_id=<?= (int) $category["id"] ?>"
                                class="text-sm font-bold text-servora-600 transition hover:text-servora-800"
                            >
                                View Plans →
                            </a>


                            <form
                                method="POST"
                                onsubmit="return confirm('Are you sure you want to <?= $isActive ? "deactivate" : "activate" ?> this category?');"
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
                                    name="category_id"
                                    value="<?= (int) $category["id"] ?>"
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
        Subnext Data Category Management
    </footer>


</main>


<script>

/*
|--------------------------------------------------------------------------
| AUTO GENERATE CATEGORY SLUG
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