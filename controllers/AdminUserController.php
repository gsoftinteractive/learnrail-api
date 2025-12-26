<?php
/**
 * Admin User Controller
 */

class AdminUserController extends Controller {

    /**
     * List all users
     * GET /api/admin/users
     */
    public function index(): void {
        $pagination = $this->paginate();
        $search = Request::query('search');
        $role = Request::query('role');
        $status = Request::query('status');

        $where = ['1=1'];
        $params = [];

        if ($search) {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($role) {
            $where[] = 'role = ?';
            $params[] = $role;
        }

        if ($status) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Get users
        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT id, email, first_name, last_name, phone, avatar, role, status,
                   total_points, last_login, created_at,
                   (SELECT status FROM subscriptions WHERE user_id = users.id AND status = 'active' LIMIT 1) as subscription_status
            FROM users
            WHERE $whereClause
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        Response::paginated($users, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Get single user
     * GET /api/admin/users/{id}
     */
    public function show(int $id): void {
        $stmt = $this->db->prepare("
            SELECT id, email, first_name, last_name, phone, avatar, role, status,
                   total_points, current_streak, longest_streak, last_login, created_at
            FROM users WHERE id = ?
        ");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('User not found');
            return;
        }

        // Get subscriptions
        $stmt = $this->db->prepare("
            SELECT s.*, sp.name as plan_name
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$id]);
        $user['subscriptions'] = $stmt->fetchAll();

        // Get enrollments
        $stmt = $this->db->prepare("
            SELECT e.*, c.title as course_title
            FROM enrollments e
            JOIN courses c ON e.course_id = c.id
            WHERE e.user_id = ?
            ORDER BY e.enrolled_at DESC
        ");
        $stmt->execute([$id]);
        $user['enrollments'] = $stmt->fetchAll();

        Response::success($user);
    }

    /**
     * Create user
     * POST /api/admin/users
     */
    public function create(): void {
        if (!$this->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
            'first_name' => 'required|min:2',
            'last_name' => 'required|min:2',
            'role' => 'required|in:user,admin,partner'
        ])) return;

        $email = Request::input('email');

        // Check email exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::validationError(['email' => 'Email already registered']);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (email, password, first_name, last_name, phone, role, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([
            $email,
            $this->hashPassword(Request::input('password')),
            Request::input('first_name'),
            Request::input('last_name'),
            Request::input('phone'),
            Request::input('role')
        ]);

        Response::created(['id' => $this->db->lastInsertId()], 'User created');
    }

    /**
     * Update user
     * PUT /api/admin/users/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('User not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['first_name', 'last_name', 'phone', 'role', 'status'];
        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }

        // Handle password update
        $password = Request::input('password');
        if ($password) {
            $updates[] = "password = ?";
            $params[] = $this->hashPassword($password);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'User updated');
    }

    /**
     * Delete user
     * DELETE /api/admin/users/{id}
     */
    public function delete(int $id): void {
        // Prevent self-delete
        if ($id == $this->userId()) {
            Response::error('Cannot delete your own account', 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('User not found');
            return;
        }

        Response::success(null, 'User deleted');
    }
}
