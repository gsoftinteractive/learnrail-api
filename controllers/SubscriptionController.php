<?php
/**
 * Subscription Controller
 */

class SubscriptionController extends Controller {

    /**
     * List subscription plans
     * GET /api/subscription-plans
     */
    public function plans(): void {
        $stmt = $this->db->prepare("
            SELECT id, name, slug, description, duration_days, price,
                   original_price, features, is_popular, is_active
            FROM subscription_plans
            WHERE is_active = 1
            ORDER BY price ASC
        ");
        $stmt->execute();
        $plans = $stmt->fetchAll();

        // Parse JSON features
        foreach ($plans as &$plan) {
            $plan['features'] = json_decode($plan['features'], true) ?? [];
        }

        Response::success($plans);
    }

    /**
     * Get user's subscriptions
     * GET /api/subscriptions
     */
    public function index(): void {
        $pagination = $this->paginate();

        // Count total
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM subscriptions WHERE user_id = ?
        ");
        $stmt->execute([$this->userId()]);
        $total = (int) $stmt->fetchColumn();

        // Get subscriptions
        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.duration_days, p.features
            FROM subscriptions s
            JOIN subscription_plans p ON s.plan_id = p.id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([
            $this->userId(),
            $pagination['per_page'],
            $pagination['offset']
        ]);
        $subscriptions = $stmt->fetchAll();

        // Parse features
        foreach ($subscriptions as &$sub) {
            $sub['features'] = json_decode($sub['features'], true) ?? [];
        }

        Response::paginated($subscriptions, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Get active subscription
     * GET /api/subscriptions/active
     */
    public function active(): void {
        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.duration_days, p.features
            FROM subscriptions s
            JOIN subscription_plans p ON s.plan_id = p.id
            WHERE s.user_id = ? AND s.status = 'active' AND s.end_date > NOW()
            ORDER BY s.end_date DESC
            LIMIT 1
        ");
        $stmt->execute([$this->userId()]);
        $subscription = $stmt->fetch();

        if ($subscription) {
            $subscription['features'] = json_decode($subscription['features'], true) ?? [];
            $subscription['days_remaining'] = (int) ceil(
                (strtotime($subscription['end_date']) - time()) / 86400
            );
        }

        Response::success($subscription);
    }

    /**
     * Get single subscription
     * GET /api/subscriptions/{id}
     */
    public function show(int $id): void {
        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.duration_days, p.features
            FROM subscriptions s
            JOIN subscription_plans p ON s.plan_id = p.id
            WHERE s.id = ? AND s.user_id = ?
        ");
        $stmt->execute([$id, $this->userId()]);
        $subscription = $stmt->fetch();

        if (!$subscription) {
            Response::notFound('Subscription not found');
            return;
        }

        $subscription['features'] = json_decode($subscription['features'], true) ?? [];

        // Get associated payments
        $stmt = $this->db->prepare("
            SELECT id, amount, payment_method, status, transaction_ref, created_at
            FROM payments
            WHERE subscription_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$id]);
        $subscription['payments'] = $stmt->fetchAll();

        Response::success($subscription);
    }

    /**
     * Create subscription (initiates payment)
     * POST /api/subscriptions
     */
    public function create(): void {
        if (!$this->validate([
            'plan_id' => 'required|integer',
            'payment_method' => 'required'
        ])) {
            return;
        }

        $planId = (int) Request::input('plan_id');
        $paymentMethod = Request::input('payment_method');

        // Verify plan exists
        $stmt = $this->db->prepare("
            SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();

        if (!$plan) {
            Response::error('Invalid subscription plan');
            return;
        }

        // Check for existing active subscription
        $stmt = $this->db->prepare("
            SELECT id FROM subscriptions
            WHERE user_id = ? AND status = 'active' AND end_date > NOW()
        ");
        $stmt->execute([$this->userId()]);
        if ($stmt->fetch()) {
            Response::error('You already have an active subscription');
            return;
        }

        // Calculate dates
        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));

        // Create pending subscription
        $stmt = $this->db->prepare("
            INSERT INTO subscriptions (user_id, plan_id, start_date, end_date, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        $stmt->execute([$this->userId(), $planId, $startDate, $endDate]);
        $subscriptionId = (int) $this->db->lastInsertId();

        // Create payment record
        $transactionRef = 'TXN_' . strtoupper(uniqid()) . '_' . time();
        $stmt = $this->db->prepare("
            INSERT INTO payments (user_id, subscription_id, amount, payment_method, transaction_ref, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $this->userId(),
            $subscriptionId,
            $plan['price'],
            $paymentMethod,
            $transactionRef
        ]);
        $paymentId = (int) $this->db->lastInsertId();

        Response::created([
            'subscription_id' => $subscriptionId,
            'payment_id' => $paymentId,
            'transaction_ref' => $transactionRef,
            'amount' => (float) $plan['price'],
            'plan' => [
                'name' => $plan['name'],
                'duration_days' => $plan['duration_days']
            ],
            'payment_method' => $paymentMethod
        ], 'Subscription created. Please complete payment.');
    }
}
