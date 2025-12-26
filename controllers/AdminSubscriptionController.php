<?php
/**
 * Admin Subscription Controller
 */

class AdminSubscriptionController extends Controller {

    /**
     * List subscription plans
     * GET /api/admin/subscription-plans
     */
    public function plans(): void {
        $stmt = $this->db->query("
            SELECT sp.*,
                   (SELECT COUNT(*) FROM subscriptions WHERE plan_id = sp.id) as total_subscriptions,
                   (SELECT COUNT(*) FROM subscriptions WHERE plan_id = sp.id AND status = 'active') as active_subscriptions
            FROM subscription_plans sp
            ORDER BY sp.sort_order
        ");
        $plans = $stmt->fetchAll();

        foreach ($plans as &$plan) {
            $plan['accessible_courses'] = json_decode($plan['accessible_courses'], true);
        }

        Response::success($plans);
    }

    /**
     * Create subscription plan
     * POST /api/admin/subscription-plans
     */
    public function createPlan(): void {
        if (!$this->validate([
            'name' => 'required|min:2|max:100',
            'duration_months' => 'required|integer',
            'price' => 'required|numeric'
        ])) return;

        $name = Request::input('name');
        $slug = $this->generateSlug($name, 'subscription_plans');

        // Get next sort order
        $stmt = $this->db->query("SELECT MAX(sort_order) FROM subscription_plans");
        $sortOrder = ((int) $stmt->fetchColumn()) + 1;

        $stmt = $this->db->prepare("
            INSERT INTO subscription_plans (name, slug, description, duration_months, price, currency,
                includes_goal_tracker, includes_accountability_partner, accessible_courses, is_active, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $name,
            $slug,
            Request::input('description'),
            Request::input('duration_months'),
            Request::input('price'),
            Request::input('currency', 'NGN'),
            Request::input('includes_goal_tracker', false) ? 1 : 0,
            Request::input('includes_accountability_partner', false) ? 1 : 0,
            json_encode(Request::input('accessible_courses')),
            Request::input('is_active', true) ? 1 : 0,
            $sortOrder
        ]);

        Response::created(['id' => $this->db->lastInsertId(), 'slug' => $slug], 'Plan created');
    }

    /**
     * Update subscription plan
     * PUT /api/admin/subscription-plans/{id}
     */
    public function updatePlan(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM subscription_plans WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Plan not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['name', 'description', 'duration_months', 'price', 'currency',
            'includes_goal_tracker', 'includes_accountability_partner', 'is_active', 'sort_order'];

        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        // Handle accessible_courses
        $accessibleCourses = Request::input('accessible_courses');
        if ($accessibleCourses !== null) {
            $updates[] = "accessible_courses = ?";
            $params[] = json_encode($accessibleCourses);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE subscription_plans SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Plan updated');
    }

    /**
     * Delete subscription plan
     * DELETE /api/admin/subscription-plans/{id}
     */
    public function deletePlan(int $id): void {
        // Check if plan has subscriptions
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM subscriptions WHERE plan_id = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            Response::error('Cannot delete plan with existing subscriptions', 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM subscription_plans WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Plan not found');
            return;
        }

        Response::success(null, 'Plan deleted');
    }

    /**
     * List all subscriptions
     * GET /api/admin/subscriptions
     */
    public function index(): void {
        $pagination = $this->paginate();
        $status = Request::query('status');

        $where = ['1=1'];
        $params = [];

        if ($status) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM subscriptions s WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT s.*, sp.name as plan_name, sp.duration_months,
                   u.first_name, u.last_name, u.email
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            JOIN users u ON s.user_id = u.id
            WHERE $whereClause
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $subscriptions = $stmt->fetchAll();

        Response::paginated($subscriptions, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Update subscription
     * PUT /api/admin/subscriptions/{id}
     */
    public function update(int $id): void {
        $status = Request::input('status');
        $endDate = Request::input('end_date');

        $updates = [];
        $params = [];

        if ($status) {
            $updates[] = "status = ?";
            $params[] = $status;

            if ($status === 'active' && !$endDate) {
                // Set start and end date if activating
                $stmt = $this->db->prepare("
                    SELECT sp.duration_months FROM subscriptions s
                    JOIN subscription_plans sp ON s.plan_id = sp.id
                    WHERE s.id = ?
                ");
                $stmt->execute([$id]);
                $plan = $stmt->fetch();

                $updates[] = "start_date = CURDATE()";
                $updates[] = "end_date = DATE_ADD(CURDATE(), INTERVAL ? MONTH)";
                $params[] = $plan['duration_months'];
            }
        }

        if ($endDate) {
            $updates[] = "end_date = ?";
            $params[] = $endDate;
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE subscriptions SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Subscription updated');
    }
}
