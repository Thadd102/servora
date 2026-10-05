<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$message = "";
$messageType = "";


/*
|--------------------------------------------------------------------------
| SUPER ADMIN ONLY
|--------------------------------------------------------------------------
*/

if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die("Access denied. Only the Super Admin can manage client funding.");
}

/*
|--------------------------------------------------------------------------
| Handle Wallet Funding
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    $clientId = filter_input(INPUT_POST, "client_id", FILTER_VALIDATE_INT);
    $amount = filter_input(INPUT_POST, "amount", FILTER_VALIDATE_FLOAT);
    $description = trim($_POST["description"] ?? "");

    // Validate client
    if (!$clientId) {
        $message = "Please select a valid client.";
        $messageType = "error";
    }

    // Validate amount
    elseif (!$amount || $amount <= 0) {
        $message = "Please enter a valid amount greater than ₦0.";
        $messageType = "error";
    }

    else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Start database transaction
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Make sure the selected user is a client
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id, full_name, email
                FROM users
                WHERE id = ?
                AND role = 'client'
                AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([$clientId]);

            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) {
                throw new Exception("Client not found or client account is not active.");
            }

            /*
            |--------------------------------------------------------------------------
            | Lock the wallet row
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id, balance
                FROM wallets
                WHERE user_id = ?
                FOR UPDATE
            ");

            $stmt->execute([$clientId]);

            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                throw new Exception("This client does not have a wallet.");
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate new balance
            |--------------------------------------------------------------------------
            */

            $balanceBefore = (float) $wallet["balance"];
            $balanceAfter = $balanceBefore + $amount;

            /*
            |--------------------------------------------------------------------------
            | Update wallet
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE wallets
                SET balance = ?
                WHERE user_id = ?
            ");

            $stmt->execute([
                $balanceAfter,
                $clientId
            ]);

            /*
            |--------------------------------------------------------------------------
            | Generate unique reference
            |--------------------------------------------------------------------------
            */

            $reference = "MANUAL-" .
                $clientId . "-" .
                time() . "-" .
                strtoupper(bin2hex(random_bytes(4)));

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            if ($description === "") {
                $description = "Manual wallet funding by admin";
            }

            /*
            |--------------------------------------------------------------------------
            | Record transaction
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO wallet_transactions
                (
                    user_id,
                    type,
                    amount,
                    balance_before,
                    balance_after,
                    reference,
                    description,
                    status
                )
                VALUES (?, 'funding', ?, ?, ?, ?, ?, 'successful')
            ");

            $stmt->execute([
                $clientId,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $reference,
                $description
            ]);

            /*
            |--------------------------------------------------------------------------
            | Send notification to client
            |--------------------------------------------------------------------------
            */

            $notificationTitle = "Wallet Funded";

            $notificationMessage =
                "₦" . number_format($amount, 2) .
                " has been added to your wallet. " .
                "Your new wallet balance is ₦" .
                number_format($balanceAfter, 2) . ".";

            $stmt = $pdo->prepare("
                INSERT INTO notifications
                (
                    user_id,
                    request_id,
                    title,
                    message,
                    is_read
                )
                VALUES (?, NULL, ?, ?, 0)
            ");

            $stmt->execute([
                $clientId,
                $notificationTitle,
                $notificationMessage
            ]);

            /*
            |--------------------------------------------------------------------------
            | Everything successful
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            $message =
                "₦" . number_format($amount, 2) .
                " successfully added to " .
                $client["full_name"] .
                "'s wallet. New balance: ₦" .
                number_format($balanceAfter, 2);

            $messageType = "success";

        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | Cancel everything if something fails
            |--------------------------------------------------------------------------
            */

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e->getMessage();
            $messageType = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Active Clients
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        u.id,
        u.full_name,
        u.email,
        w.balance
    FROM users u
    LEFT JOIN wallets w
        ON w.user_id = u.id
    WHERE u.role = 'client'
    AND u.status = 'active'
    ORDER BY u.full_name ASC
");

$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Fund Client - Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body class="bg-gray-100 min-h-screen">

    <!-- Header -->

    <header class="bg-servora-700 text-white shadow">

        <div class="max-w-7xl mx-auto px-6 py-4">

            <div class="flex items-center justify-between">

                <div>
                    <h1 class="text-xl font-bold">
                        Subnext Admin
                    </h1>

                    <p class="text-sm text-purple-200">
                        Fund Client Wallet
                    </p>
                </div>

                <a
                    href="dashboard.php"
                    class="bg-white text-servora-700 px-4 py-2 rounded-lg font-medium hover:bg-gray-100"
                >
                    Dashboard
                </a>

            </div>

        </div>

    </header>


    <!-- Main -->

    <main class="max-w-4xl mx-auto px-6 py-10">

        <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8">

            <div class="mb-8">

                <h2 class="text-2xl font-bold text-gray-800">
                    Fund Client Wallet
                </h2>

                <p class="text-gray-500 mt-1">
                    Add money manually to a client's wallet.
                </p>

            </div>


            <!-- Message -->

            <?php if ($message): ?>

                <div
                    class="mb-6 p-4 rounded-lg
                    <?= $messageType === 'success'
                        ? 'bg-green-50 text-green-700 border border-green-200'
                        : 'bg-red-50 text-red-700 border border-red-200'
                    ?>"
                >

                    <?= htmlspecialchars($message) ?>

                </div>

            <?php endif; ?>


            <!-- Form -->

            <form method="POST" class="space-y-6">
<?= csrfField() ?>


                <!-- Client -->

                <div>

                    <label
                        for="client_id"
                        class="block text-sm font-semibold text-gray-700 mb-2"
                    >
                        Select Client
                    </label>

                    <select
                        name="client_id"
                        id="client_id"
                        required
                        class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-servora-500 focus:outline-none"
                    >

                        <option value="">
                            -- Select Client --
                        </option>

                        <?php foreach ($clients as $client): ?>

                            <option value="<?= (int) $client["id"] ?>">

                                <?= htmlspecialchars($client["full_name"]) ?>

                                —
                                <?= htmlspecialchars($client["email"]) ?>

                                —
                                Current Balance: ₦<?= number_format((float)($client["balance"] ?? 0), 2) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Amount -->

                <div>

                    <label
                        for="amount"
                        class="block text-sm font-semibold text-gray-700 mb-2"
                    >
                        Amount to Add
                    </label>

                    <div class="relative">

                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500">
                            ₦
                        </span>

                        <input
                            type="number"
                            name="amount"
                            id="amount"
                            min="1"
                            step="0.01"
                            required
                            placeholder="Enter amount"
                            class="w-full border border-gray-300 rounded-lg pl-10 pr-4 py-3 focus:ring-2 focus:ring-servora-500 focus:outline-none"
                        >

                    </div>

                </div>


                <!-- Description -->

                <div>

                    <label
                        for="description"
                        class="block text-sm font-semibold text-gray-700 mb-2"
                    >
                        Description
                        <span class="font-normal text-gray-400">
                            (Optional)
                        </span>
                    </label>

                    <textarea
                        name="description"
                        id="description"
                        rows="3"
                        placeholder="Example: Client paid ₦5,000 cash"
                        class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-servora-500 focus:outline-none"
                    ></textarea>

                </div>


                <!-- Warning -->

                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">

                    <p class="text-sm text-yellow-800">

                        <strong>Important:</strong>

                        Make sure you have confirmed the client's payment before adding funds.

                        This action will immediately increase the client's wallet balance and create a transaction record.

                    </p>

                </div>


                <!-- Submit -->

                <button
                    type="submit"
                    class="w-full bg-servora-700 hover:bg-servora-800 text-white font-semibold py-3 rounded-lg transition"
                >
                    Fund Client Wallet
                </button>

            </form>

        </div>

    </main>

</body>

</html>