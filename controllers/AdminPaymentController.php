<?php
/**
 * Admin Payment Controller
 */

class AdminPaymentController extends Controller {

    /**
     * List all payments
     * GET /api/admin/payments
     */
    public function index(): void {
        $pagination = $this->paginate();
        $status = Request::query('status');
        $method = Request::query('method');

        $where = ['1=1'];
        $params = [];

        if ($status) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }

        if ($method) {
            $where[] = 'p.payment_method = ?';
            $params[] = $method;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payments p WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT p.*, u.first_name, u.last_name, u.email,
                   sp.name as plan_name
            FROM payments p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN subscriptions s ON p.subscription_id = s.id
            LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE $whereClause
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $payments = $stmt->fetchAll();

        foreach ($payments as &$payment) {
            $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
        }

        // Get stats
        $stmt = $this->db->query("
            SELECT
                COUNT(*) as total_payments,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue
            FROM payments
        ");
        $stats = $stmt->fetch();

        Response::json([
            'success' => true,
            'data' => $payments,
            'stats' => $stats,
            'meta' => [
                'current_page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
                'last_page' => ceil($total / $pagination['per_page']),
                'has_more' => $pagination['page'] < ceil($total / $pagination['per_page'])
            ]
        ]);
    }

    /**
     * Approve bank transfer payment
     * PUT /api/admin/payments/{id}/approve
     */
    public function approve(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch();

        if (!$payment) {
            Response::notFound('Payment not found');
            return;
        }

        if ($payment['status'] !== 'pending') {
            Response::error('Only pending payments can be approved', 400);
            return;
        }

        // Update payment
        $stmt = $this->db->prepare("UPDATE payments SET status = 'completed', paid_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);

        // Activate subscription
        $stmt = $this->db->prepare("SELECT plan_id FROM subscriptions WHERE id = ?");
        $stmt->execute([$payment['subscription_id']]);
        $subscription = $stmt->fetch();

        $stmt = $this->db->prepare("SELECT duration_months FROM subscription_plans WHERE id = ?");
        $stmt->execute([$subscription['plan_id']]);
        $plan = $stmt->fetch();

        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$plan['duration_months']} months"));

        $stmt = $this->db->prepare("
            UPDATE subscriptions
            SET status = 'active', payment_reference = ?, start_date = ?, end_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$payment['reference'], $startDate, $endDate, $payment['subscription_id']]);

        // Send notification to user
        NotificationController::create(
            $this->db,
            $payment['user_id'],
            'Payment Approved',
            'Your payment has been approved and your subscription is now active!',
            'payment',
            ['payment_id' => $id, 'subscription_id' => $payment['subscription_id']]
        );

        Response::success([
            'subscription_end_date' => $endDate
        ], 'Payment approved and subscription activated');
    }

    /**
     * Reject payment
     * PUT /api/admin/payments/{id}/reject
     */
    public function reject(int $id): void {
        $reason = Request::input('reason', 'Payment could not be verified');

        $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch();

        if (!$payment) {
            Response::notFound('Payment not found');
            return;
        }

        // Update payment
        $stmt = $this->db->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
        $stmt->execute([$id]);

        // Cancel subscription
        $stmt = $this->db->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$payment['subscription_id']]);

        // Notify user
        NotificationController::create(
            $this->db,
            $payment['user_id'],
            'Payment Rejected',
            $reason,
            'payment',
            ['payment_id' => $id]
        );

        Response::success(null, 'Payment rejected');
    }
}
