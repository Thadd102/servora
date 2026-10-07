<?php

require_once "config/database.php";

require_once __DIR__ . "/includes/auth.php";

$error = "";
$success = "";

// User must pass OTP verification first
if (
    empty($_SESSION["password_reset_user_id"]) ||
    empty($_SESSION["password_reset_verified"]) ||
    empty($_SESSION["password_reset_id"])
) {
    header("Location: forgot_password.php");
    exit;
}

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["password_reset_user_id"];
$resetId = (int) $_SESSION["password_reset_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRF protection
    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please try again.";

    } else {

        $password = $_POST["password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if (strlen($password) < 8) {

            $error = "Password must be at least 8 characters.";

        } elseif ($password !== $confirmPassword) {

            $error = "Passwords do not match.";

        } else {

            try {

                $pdo->beginTransaction();

                // Lock reset record
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM password_resets
                    WHERE id = ?
                    AND user_id = ?
                    AND used = 0
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $resetId,
                    $userId
                ]);

                $reset = $stmt->fetch();

                if (!$reset) {

                    throw new Exception(
                        "This password reset session is no longer valid."
                    );
                }

                // Check expiry again
                if (
                    strtotime($reset["expires_at"]) < time()
                ) {

                    throw new Exception(
                        "Your OTP has expired."
                    );
                }

                // Hash new password
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Update password
                $update = $pdo->prepare("
                    UPDATE users
                    SET password = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $update->execute([
                    $passwordHash,
                    $userId
                ]);

                // Mark OTP as used
                $markUsed = $pdo->prepare("
                    UPDATE password_resets
                    SET used = 1
                    WHERE id = ?
                ");

                $markUsed->execute([
                    $resetId
                ]);

                $pdo->commit();

                // Destroy reset session data
                unset(
                    $_SESSION["password_reset_user_id"],
                    $_SESSION["password_reset_verified"],
                    $_SESSION["password_reset_id"]
                );

                // New CSRF token
                $_SESSION["csrf_token"] =
                    bin2hex(random_bytes(32));

                $success =
                    "Password changed successfully.";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Subnext</title>
    <meta name="description" content="Choose a new secure password for your Subnext account.">

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body class="min-h-screen bg-[#F6F7FB] flex items-center justify-center p-4 sm:p-6 text-slate-900 antialiased">

    <div class="w-full max-w-md">

        <!-- CARD -->
        <div class="rounded-3xl border border-slate-200/80 bg-white p-6 sm:p-8 shadow-xl shadow-slate-200/50">

            <!-- LOGO & HEADER -->
            <div class="text-center mb-6">

                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-[#3E37B7] text-white shadow-lg shadow-indigo-200 mb-3">
                    <span class="text-2xl font-black">S</span>
                </div>

                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
                    New Password
                </h1>

                <p class="text-xs text-slate-500 mt-1 max-w-xs mx-auto">
                    Create a strong, unique password with at least 8 characters.
                </p>

            </div>

            <?php if ($error): ?>
                <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50/90 p-3.5 text-xs font-semibold text-rose-800 flex items-start gap-2.5">
                    <span class="text-base shrink-0 leading-none">⚠️</span>
                    <span class="leading-relaxed"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 text-xs sm:text-sm font-semibold text-emerald-800 text-center">
                    <div class="text-2xl mb-1">✓</div>
                    <?= htmlspecialchars($success) ?>
                </div>

                <a
                    href="login.php"
                    class="block w-full text-center h-13 sm:h-14 leading-[3.25rem] sm:leading-[3.5rem] rounded-xl bg-[#3E37B7] text-white font-bold text-sm shadow-lg shadow-indigo-200 transition duration-200 hover:bg-[#312E81] hover:shadow-xl active:scale-[0.98]"
                >
                    Continue to Login →
                </a>

            <?php else: ?>

                <form method="POST" class="space-y-4">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                    >

                    <div>
                        <label for="password" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                            New Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            minlength="8"
                            placeholder="At least 8 characters"
                            required
                            class="w-full
                            h-13 sm:h-14
                            rounded-xl
                            border border-slate-200
                            bg-slate-50
                            px-4
                            text-sm
                            text-slate-900
                            placeholder-slate-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                            Confirm Password
                        </label>

                        <input
                            type="password"
                            name="confirm_password"
                            id="confirm_password"
                            minlength="8"
                            placeholder="Re-enter your password"
                            required
                            class="w-full
                            h-13 sm:h-14
                            rounded-xl
                            border border-slate-200
                            bg-slate-50
                            px-4
                            text-sm
                            text-slate-900
                            placeholder-slate-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full
                        h-13 sm:h-14
                        rounded-xl
                        bg-[#3E37B7]
                        text-white
                        font-bold
                        text-sm
                        shadow-lg
                        shadow-indigo-200
                        transition-all
                        duration-200
                        hover:bg-[#312E81]
                        hover:shadow-xl
                        active:scale-[0.98]"
                    >
                        Reset Password →
                    </button>

                </form>

            <?php endif; ?>

            <div class="mt-6 pt-5 border-t border-slate-100 text-center">
                <a
                    href="login.php"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#5146C7] hover:text-[#312E81] transition"
                >
                    <span>←</span>
                    <span>Back to Login</span>
                </a>
            </div>

        </div>

        <!-- FOOTER -->
        <p class="text-center text-[11px] text-slate-400 mt-6">
            © <?= date('Y') ?> Subnext. Digital Services, Simplified.
        </p>

    </div>

    <script src="assets/js/loader.js" defer></script>
</body>

</html>