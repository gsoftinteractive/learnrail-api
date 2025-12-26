<?php
/**
 * Payment Controller
 */

class PaymentController extends Controller {

    /**
     * List user's payments
     * GET /api/payments
     */
    public function index(): void {
        $userId = $this->userId();
        $pagination = $this->paginate();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ?");
        $stmt->execute([$userId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT p.id, p.amount, p.currency, p.payment_method, p.reference,
                   p.status, p.paid_at, p.created_at,
                   sp.name as plan_name, sp.duration_months
            FROM payments p
            LEFT JOIN subscriptions s ON p.subscription_id = s.id
            LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $pagination['per_page'], $pagination['offset']]);
        $payments = $stmt->fetchAll();

        Response::paginated($payments, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Initialize payment
     * POST /api/payments/initialize
     */
    public function initialize(): void {
        if (!$this->validate([
            'plan_id' => 'required|integer',
            'payment_method' => 'required|in:paystack,bank_transfer,xpress'
        ])) return;

        $userId = $this->userId();
        $planId = Request::input('plan_id');
        $paymentMethod = Request::input('payment_method');

        // Get plan
        $stmt = $this->db->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();

        if (!$plan) {
            Response::notFound('Plan not found');
            return;
        }

        // Check for existing active subscription
        $stmt = $this->db->prepare("
            SELECT id FROM subscriptions
            WHERE user_id = ? AND status = 'active' AND end_date > NOW()
        ");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            Response::error('You already have an active subscription', 400);
            return;
        }

        // Generate reference
        $reference = 'LR_' . strtoupper(bin2hex(random_bytes(8))) . '_' . time();

        // Create pending subscription
        $stmt = $this->db->prepare("
            INSERT INTO subscriptions (user_id, plan_id, status, payment_method, amount_paid, created_at)
            VALUES (?, ?, 'pending', ?, ?, NOW())
        ");
        $stmt->execute([$userId, $planId, $paymentMethod, $plan['price']]);
        $subscriptionId = $this->db->lastInsertId();

        // Create payment record
        $stmt = $this->db->prepare("
            INSERT INTO payments (user_id, subscription_id, amount, currency, payment_method, reference, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$userId, $subscriptionId, $plan['price'], $plan['currency'], $paymentMethod, $reference]);

        $response = [
            'reference' => $reference,
            'amount' => $plan['price'],
            'currency' => $plan['currency'],
            'payment_method' => $paymentMethod,
            'subscription_id' => $subscriptionId
        ];

        // Add Paystack initialization if needed
        if ($paymentMethod === 'paystack') {
            $user = $this->user();
            $response['paystack'] = $this->initializePaystack($reference, $plan['price'], $user['email']);
        }

        // Add bank details for bank transfer
        if ($paymentMethod === 'bank_transfer') {
            $response['bank_details'] = [
                'bank_name' => 'Access Bank',
                'account_number' => '1234567890',
                'account_name' => 'Learnrail Limited'
            ];
        }

        Response::created($response, 'Payment initialized');
    }

    /**
     * Verify payment
     * POST /api/payments/verify
     */
    public function verify(): void {
        if (!$this->validate(['reference' => 'required'])) return;

        $reference = Request::input('reference');

        // Get payment
        $stmt = $this->db->prepare("
            SELECT p.*, s.plan_id
            FROM payments p
            JOIN subscriptions s ON p.subscription_id = s.id
            WHERE p.reference = ?
        ");
        $stmt->execute([$reference]);
        $payment = $stmt->fetch();

        if (!$payment) {
            Response::notFound('Payment not found');
            return;
        }

        if ($payment['status'] === 'completed') {
            Response::success(['status' => 'already_verified', 'message' => 'Payment already verified']);
            return;
        }

        // Verify with gateway
        $verified = false;
        if ($payment['payment_method'] === 'paystack') {
            $verified = $this->verifyPaystack($reference);
        } elseif ($payment['payment_method'] === 'bank_transfer') {
            // Bank transfers are verified manually by admin
            Response::success(['status' => 'pending', 'message' => 'Bank transfer pending verification']);
            return;
        }

        if (!$verified) {
            Response::error('Payment verification failed', 400);
            return;
        }

        // Update payment
        $stmt = $this->db->prepare("UPDATE payments SET status = 'completed', paid_at = NOW() WHERE id = ?");
        $stmt->execute([$payment['id']]);

        // Activate subscription
        $stmt = $this->db->prepare("SELECT duration_months FROM subscription_plans WHERE id = ?");
        $stmt->execute([$payment['plan_id']]);
        $plan = $stmt->fetch();

        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$plan['duration_months']} months"));

        $stmt = $this->db->prepare("
            UPDATE subscriptions
            SET status = 'active', payment_reference = ?, start_date = ?, end_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$reference, $startDate, $endDate, $payment['subscription_id']]);

        Response::success([
            'status' => 'verified',
            'subscription_active' => true,
            'end_date' => $endDate
        ]);
    }

    /**
     * Initialize Paystack payment
     */
    private function initializePaystack(string $reference, float $amount, string $email): array {
        $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: '';

        $data = [
            'email' => $email,
            'amount' => $amount * 100, // Paystack uses kobo
            'reference' => $reference,
            'callback_url' => getenv('APP_URL') . '/payment/callback'
        ];

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        return [
            'authorization_url' => $result['data']['authorization_url'] ?? null,
            'access_code' => $result['data']['access_code'] ?? null
        ];
    }

    /**
     * Verify Paystack payment
     */
    private function verifyPaystack(string $reference): bool {
        $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: '';

        $ch = curl_init('https://api.paystack.co/transaction/verify/' . $reference);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secretKey
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        return ($result['status'] ?? false) && ($result['data']['status'] ?? '') === 'success';
    }
}
