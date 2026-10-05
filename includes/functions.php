<?php

/*
|--------------------------------------------------------------------------
| General Helper Functions
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Process a Successful Wallet Funding
|--------------------------------------------------------------------------
|
| This function is the ONE place responsible for actually
| adding money to a user's wallet.
|
| Both:
|   - verify_payment.php
|   - paystack_webhook.php
|
| will use this same function.
|
| This helps prevent duplicate wallet credits.
|
*/

function processWalletFunding(
    PDO $pdo,
    string $reference,
    float $paidAmount,
    string $paidEmail,
    string $gateway = 'Paystack'
): void {

    /*
     * Start database transaction.
     */
    $pdo->beginTransaction();

    try {

        /*
         * Find the wallet transaction and lock it.
         *
         * Only a transaction created by our system
         * can be used to fund a wallet.
         */
        $stmt = $pdo->prepare("
            SELECT
                id,
                user_id,
                amount,
                status
            FROM wallet_transactions
            WHERE reference = ?
              AND type = 'funding'
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([$reference]);

        $transaction = $stmt->fetch();


        /*
         * The payment reference must exist in our database.
         */
        if (!$transaction) {

            throw new Exception(
                "Wallet funding transaction not found."
            );

        }


        /*
         * If another process already completed this payment,
         * stop here.
         *
         * This is what prevents double crediting.
         */
        if ($transaction["status"] === "successful") {

            $pdo->commit();

            return;

        }


        /*
         * Only pending transactions can be processed.
         */
        if ($transaction["status"] !== "pending") {

            throw new Exception(
                "Wallet transaction cannot be processed."
            );

        }


        /*
         * Make sure the amount paid matches
         * the amount originally requested.
         */
        if (
            abs(
                (float) $transaction["amount"] - $paidAmount
            ) > 0.001
        ) {

            throw new Exception(
                "Payment amount does not match."
            );

        }


        /*
         * Get the user's email.
         */
        $stmt = $pdo->prepare("
            SELECT email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $transaction["user_id"]
        ]);

        $user = $stmt->fetch();


        if (!$user) {

            throw new Exception(
                "User account not found."
            );

        }


        /*
         * Confirm that the Paystack customer email
         * belongs to the user who owns the transaction.
         */
        if (
            strtolower(trim($user["email"])) !==
            strtolower(trim($paidEmail))
        ) {

            throw new Exception(
                "Payment email does not match."
            );

        }


        /*
         * Lock the wallet before changing its balance.
         */
        $stmt = $pdo->prepare("
            SELECT balance
            FROM wallets
            WHERE user_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $transaction["user_id"]
        ]);

        $wallet = $stmt->fetch();


        if (!$wallet) {

            throw new Exception(
                "Wallet not found."
            );

        }


        /*
         * Calculate the new balance.
         */
        $balanceBefore = (float) $wallet["balance"];

        $balanceAfter = $balanceBefore + $paidAmount;


        /*
         * Update the wallet balance.
         */
        $stmt = $pdo->prepare("
            UPDATE wallets
            SET
                balance = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");

        $stmt->execute([
            $balanceAfter,
            $transaction["user_id"]
        ]);


        /*
         * Mark the wallet transaction as successful.
         */
        $stmt = $pdo->prepare("
            UPDATE wallet_transactions
            SET
                amount = ?,
                balance_before = ?,
                balance_after = ?,
                status = 'successful',
                description = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $paidAmount,
            $balanceBefore,
            $balanceAfter,
            "Wallet funding via " . $gateway,
            $transaction["id"]
        ]);


        /*
         * Save both the wallet update and
         * transaction update together.
         */
        $pdo->commit();


    } catch (Throwable $e) {

        /*
         * If anything fails, undo the database changes.
         */
        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        /*
         * Pass the error back to the calling file.
         */
        throw $e;

    }
}