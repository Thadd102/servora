<?php

require_once __DIR__ . "/config/env.php";

/*
|--------------------------------------------------------------------------
| Super Admin Creation Security Guard
|--------------------------------------------------------------------------
| Creates a new Servora Super Admin account.
| Only allowed when ALLOW_ADMIN_BOOTSTRAP=1 in the environment.
|--------------------------------------------------------------------------
*/

$allowBootstrap = getenv('ALLOW_ADMIN_BOOTSTRAP') !== false
    ? getenv('ALLOW_ADMIN_BOOTSTRAP')
    : ($_ENV['ALLOW_ADMIN_BOOTSTRAP'] ?? '0');

if ((string)$allowBootstrap !== '1') {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>403 Forbidden - Servora</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-white rounded-2xl border border-slate-200 p-8 text-center shadow-sm">
            <div class="w-12 h-12 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mx-auto mb-4 font-bold text-xl">✕</div>
            <h1 class="text-xl font-bold text-slate-900 mb-2">403 Forbidden</h1>
            <p class="text-sm text-slate-500 mb-6">Super Admin bootstrap is disabled. Set <code class="bg-slate-100 px-1.5 py-0.5 rounded text-slate-800 text-xs font-mono font-bold">ALLOW_ADMIN_BOOTSTRAP=1</code> in your environment to enable account creation.</p>
            <a href="login.php" class="inline-block rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition">Return to Login</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

$message = "";
$success = false;


/*
|--------------------------------------------------------------------------
| Process Form
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Verify CSRF token
    verifyCsrfToken();

    $fullName = trim($_POST["full_name"] ?? "");
    $email = strtolower(trim($_POST["email"] ?? ""));
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($fullName === "") {

        $message = "Please enter the full name.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";

    } elseif ($phone === "") {

        $message = "Please enter the phone number.";

    } elseif ($password === "") {

        $message = "Please enter a password.";

    } elseif (strlen($password) < 8) {

        $message = "Password must be at least 8 characters.";

    } elseif ($password !== $confirmPassword) {

        $message = "Passwords do not match.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check Email
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetch()) {

            $message = "An account with this email already exists.";

        } else {

            try {

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | Hash Password
                |--------------------------------------------------------------------------
                */

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


                /*
                |--------------------------------------------------------------------------
                | Create Super Admin
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (
                        full_name,
                        email,
                        phone,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        'super_admin',
                        'active'
                    )
                ");

                $stmt->execute([
                    $fullName,
                    $email,
                    $phone,
                    $hashedPassword
                ]);

                $superAdminId = (int) $pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | Create Wallet
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO wallets
                    (
                        user_id,
                        balance
                    )
                    VALUES
                    (
                        ?,
                        0.00
                    )
                ");

                $stmt->execute([$superAdminId]);


                /*
                |--------------------------------------------------------------------------
                | Commit Transaction
                |--------------------------------------------------------------------------
                */

                $pdo->commit();

                $success = true;

                $message = "Super Admin account created successfully.";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = "Unable to create the Super Admin account.";
            }
        }
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

    <title>Create Super Admin | Servora</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            width: 100%;
            max-width: 500px;
            margin: auto;
            padding: 40px 20px;
        }

        .card {
            background: #ffffff;
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #e5e5e5;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 8px;
        }

        .description {
            margin-top: 0;
            margin-bottom: 25px;
            color: #666;
            line-height: 1.5;
        }

        .message {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 6px;
            background: #ffe9e9;
            color: #b00020;
        }

        .success {
            background: #e8f5e9;
            color: #16803c;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 16px;
            outline: none;
        }

        input:focus {
            border-color: #222;
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            background: #222;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #000;
        }

        a {
            display: inline-block;
            margin-top: 15px;
            color: #222;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .warning {
            margin-top: 20px;
            padding: 12px;
            background: #fff8e1;
            color: #765c00;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.5;
        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>Create Super Admin</h1>

        <p class="description">
            Create a Servora Super Administrator account.
        </p>


        <?php if ($message !== ""): ?>

            <div class="message <?= $success ? 'success' : '' ?>">
                <?= htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>

        <?php endif; ?>


        <?php if (!$success): ?>

            <form method="POST" autocomplete="off">

                <?= csrfField() ?>


                <label for="full_name">
                    Full Name
                </label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    value="<?= htmlspecialchars(
                        $_POST["full_name"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >


                <label for="email">
                    Email
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars(
                        $_POST["email"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >


                <label for="phone">
                    Phone Number
                </label>

                <input
                    type="text"
                    id="phone"
                    name="phone"
                    value="<?= htmlspecialchars(
                        $_POST["phone"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >


                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >


                <label for="confirm_password">
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >


                <button type="submit">
                    Create Super Admin
                </button>

            </form>


            <div class="warning">
                Security notice: do not leave this page publicly
                accessible after creating the required Super Admin.
            </div>


        <?php else: ?>

            <p>
                The new Super Admin account is ready.
            </p>

            <a href="login.php">
                Go to Login
            </a>

        <?php endif; ?>

    </div>

</div>

</body>

</html>