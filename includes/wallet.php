<?php
/**
 * Wallet system — balance management, booking fee deductions, transaction logging.
 * Requires db() and getPlatformSettings() (functions.php) to be loaded first.
 */

function getWalletBalance(int $businessId): float {
    $stmt = db()->prepare("SELECT wallet_balance FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    return (float)($stmt->fetchColumn() ?? 0.0);
}

function getBookingFeeRate(int $businessId): float {
    $platform = getPlatformSettings();
    $stmt = db()->prepare("SELECT payment_mode FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    $mode = $stmt->fetchColumn() ?: 'platform';
    return $mode === 'own'
        ? (float)($platform['rate_own_gateway']      ?? 5.00)
        : (float)($platform['rate_platform_gateway'] ?? 20.00);
}

function hasEnoughBalance(int $businessId): bool {
    return getWalletBalance($businessId) >= getBookingFeeRate($businessId);
}

/**
 * Credit wallet — adds amount and logs transaction.
 */
function creditWallet(int $businessId, float $amount, string $description, ?string $referenceId = null): bool {
    $pdo = db();
    $pdo->prepare("UPDATE businesses SET wallet_balance = wallet_balance + ? WHERE id = ?")
        ->execute([$amount, $businessId]);
    $balanceAfter = getWalletBalance($businessId);
    $pdo->prepare("
        INSERT INTO wallet_transactions (business_id, type, amount, description, reference_id, balance_after)
        VALUES (?, 'credit', ?, ?, ?, ?)
    ")->execute([$businessId, $amount, $description, $referenceId, $balanceAfter]);
    return true;
}

/**
 * Debit wallet — atomic check + deduct. Returns false if balance insufficient.
 */
function debitWallet(int $businessId, float $amount, string $description, ?int $appointmentId = null): bool {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT wallet_balance FROM businesses WHERE id = ? FOR UPDATE");
        $stmt->execute([$businessId]);
        $balance = (float)$stmt->fetchColumn();
        if ($balance < $amount) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare("UPDATE businesses SET wallet_balance = wallet_balance - ? WHERE id = ?")
            ->execute([$amount, $businessId]);
        $balanceAfter = $balance - $amount;
        $pdo->prepare("
            INSERT INTO wallet_transactions (business_id, type, amount, description, appointment_id, balance_after)
            VALUES (?, 'debit', ?, ?, ?, ?)
        ")->execute([$businessId, $amount, $description, $appointmentId, $balanceAfter]);

        // Low balance alert — notify via WhatsApp if connected
        $platform = getPlatformSettings();
        $lowAlert = (float)($platform['low_balance_alert'] ?? 100.00);
        if ($balanceAfter < $lowAlert && $balanceAfter >= 0) {
            _sendLowBalanceAlert($businessId, $balanceAfter);
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Deduct booking fee based on business payment_mode (platform=₹20, own=₹5).
 */
function deductBookingFee(int $businessId, int $appointmentId): bool {
    $rate = getBookingFeeRate($businessId);
    return debitWallet($businessId, $rate, "Booking fee — Appointment #$appointmentId", $appointmentId);
}

/**
 * Send a WhatsApp low-balance warning to the business owner's registered number.
 */
function _sendLowBalanceAlert(int $businessId, float $balance): void {
    try {
        $stmt = db()->prepare("SELECT phone FROM businesses WHERE id = ?");
        $stmt->execute([$businessId]);
        $phone = (string)$stmt->fetchColumn();
        if ($phone === '' || !isWhatsappConnected($businessId)) return;

        $msg = "⚠️ *BookWA Wallet Alert*\n\nYour wallet balance is low: *₹" . number_format($balance, 2) . "*\n\nPlease recharge to continue accepting bookings.\n\n🔗 Login → Dashboard → Wallet";
        sendWhatsappMessage($businessId, $phone, $msg);
    } catch (Exception $e) {}
}

function getWalletTransactions(int $businessId, int $limit = 20, int $offset = 0): array {
    $stmt = db()->prepare("
        SELECT * FROM wallet_transactions
        WHERE business_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$businessId, $limit, $offset]);
    return $stmt->fetchAll();
}

function countWalletTransactions(int $businessId): int {
    $stmt = db()->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE business_id = ?");
    $stmt->execute([$businessId]);
    return (int)$stmt->fetchColumn();
}

function getRechargePackages(bool $activeOnly = true): array {
    $sql = "SELECT * FROM recharge_packages";
    if ($activeOnly) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order ASC, amount ASC";
    return db()->query($sql)->fetchAll();
}
