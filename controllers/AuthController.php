<?php
/**
 * Authentication Controller
 */

class AuthController extends Controller {

    /**
     * Register new user
     * POST /api/auth/register
     */
    public function register(): void {
        if (!$this->validate([
            'first_name' => 'required|min:2|max:50',
            'last_name' => 'required|min:2|max:50',
            'email' => 'required|email',
            'password' => 'required|min:8',
        ])) {
            return;
        }

        $firstName = Request::input('first_name');
        $lastName = Request::input('last_name');
        $email = strtolower(trim(Request::input('email')));
        $password = Request::input('password');
        $phone = Request::input('phone');

        // Check if email exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            Response::error('Email already registered', 409);
            return;
        }

        // Create user
        $stmt = $this->db->prepare("
            INSERT INTO users (first_name, last_name, email, password, phone, role, total_points, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'user', 0, NOW(), NOW())
        ");

        $stmt->execute([
            $firstName,
            $lastName,
            $email,
            $this->hashPassword($password),
            $phone
        ]);

        $userId = (int) $this->db->lastInsertId();

        // Generate tokens
        $token = JWT::generate(['user_id' => $userId, 'role' => 'user']);
        $refreshToken = JWT::generateRefreshToken($userId);

        // Get user data
        $user = $this->getUserById($userId);

        Response::created([
            'user' => $user,
            'token' => $token,
            'refresh_token' => $refreshToken
        ], 'Registration successful');
    }

    /**
     * Login user
     * POST /api/auth/login
     */
    public function login(): void {
        if (!$this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ])) {
            return;
        }

        $email = strtolower(trim(Request::input('email')));
        $password = Request::input('password');

        // Find user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !$this->verifyPassword($password, $user['password'])) {
            Response::error('Invalid email or password', 401);
            return;
        }

        // Check if user is active
        if (isset($user['status']) && $user['status'] !== 'active') {
            Response::error('Account is not active', 403);
            return;
        }

        // Update last login
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Generate tokens
        $token = JWT::generate(['user_id' => $user['id'], 'role' => $user['role']]);
        $refreshToken = JWT::generateRefreshToken($user['id']);

        // Get user data (without password)
        $userData = $this->getUserById($user['id']);

        Response::success([
            'user' => $userData,
            'token' => $token,
            'refresh_token' => $refreshToken
        ], 'Login successful');
    }

    /**
     * Refresh token
     * POST /api/auth/refresh
     */
    public function refresh(): void {
        $refreshToken = Request::input('refresh_token');

        if (!$refreshToken) {
            Response::error('Refresh token required', 400);
            return;
        }

        $payload = JWT::validate($refreshToken);

        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            Response::error('Invalid refresh token', 401);
            return;
        }

        $userId = $payload['user_id'];

        // Get user
        $stmt = $this->db->prepare("SELECT id, role, status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
            return;
        }

        if (isset($user['status']) && $user['status'] !== 'active') {
            Response::error('Account is not active', 403);
            return;
        }

        // Generate new tokens
        $token = JWT::generate(['user_id' => $user['id'], 'role' => $user['role']]);
        $newRefreshToken = JWT::generateRefreshToken($user['id']);

        Response::success([
            'token' => $token,
            'refresh_token' => $newRefreshToken
        ]);
    }

    /**
     * Get current user
     * GET /api/auth/me
     */
    public function me(): void {
        $user = $this->getUserById($this->userId());

        if (!$user) {
            Response::notFound('User not found');
            return;
        }

        // Get active subscription
        $stmt = $this->db->prepare("
            SELECT s.*, sp.name as plan_name, sp.slug as plan_slug,
                   sp.includes_goal_tracker, sp.includes_accountability_partner,
                   sp.accessible_courses
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active' AND s.end_date > NOW()
            ORDER BY s.end_date DESC
            LIMIT 1
        ");
        $stmt->execute([$this->userId()]);
        $subscription = $stmt->fetch();

        $user['subscription'] = $subscription ?: null;

        // Get accountability partner if assigned
        if ($subscription && $subscription['includes_accountability_partner']) {
            $stmt = $this->db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.avatar
                FROM accountability_assignments aa
                JOIN users u ON aa.partner_id = u.id
                WHERE aa.user_id = ? AND aa.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$this->userId()]);
            $user['accountability_partner'] = $stmt->fetch() ?: null;
        }

        Response::success($user);
    }

    /**
     * Forgot password
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(): void {
        if (!$this->validate([
            'email' => 'required|email',
        ])) {
            return;
        }

        $email = strtolower(trim(Request::input('email')));

        // Find user
        $stmt = $this->db->prepare("SELECT id, first_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate reset token
            $resetToken = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Save token
            $stmt = $this->db->prepare("
                INSERT INTO password_resets (email, token, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()
            ");
            $stmt->execute([$email, $resetToken, $expiry]);

            // TODO: Send email with reset link
            // $resetLink = APP_URL . "/reset-password?token=" . $resetToken;
            // sendEmail($email, 'Reset Password', "Click here to reset: $resetLink");
        }

        // Always return success to prevent email enumeration
        Response::success(null, 'If the email exists, a reset link has been sent');
    }

    /**
     * Reset password
     * POST /api/auth/reset-password
     */
    public function resetPassword(): void {
        if (!$this->validate([
            'token' => 'required',
            'password' => 'required|min:8',
        ])) {
            return;
        }

        $token = Request::input('token');
        $password = Request::input('password');

        // Find valid token
        $stmt = $this->db->prepare("
            SELECT email FROM password_resets
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            Response::error('Invalid or expired reset token', 400);
            return;
        }

        // Update password
        $stmt = $this->db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?");
        $stmt->execute([$this->hashPassword($password), $reset['email']]);

        // Delete reset token
        $stmt = $this->db->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$reset['email']]);

        Response::success(null, 'Password reset successful');
    }

    /**
     * Logout (invalidate token - optional with JWT)
     * POST /api/auth/logout
     */
    public function logout(): void {
        // With JWT, logout is typically handled client-side by removing the token
        // Optionally, you could maintain a blacklist of invalidated tokens
        Response::success(null, 'Logged out successfully');
    }

    /**
     * Change password
     * POST /api/auth/change-password
     */
    public function changePassword(): void {
        if (!$this->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8',
        ])) {
            return;
        }

        $currentPassword = Request::input('current_password');
        $newPassword = Request::input('new_password');

        // Get user with password
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$this->userId()]);
        $user = $stmt->fetch();

        if (!$this->verifyPassword($currentPassword, $user['password'])) {
            Response::error('Current password is incorrect', 400);
            return;
        }

        // Update password
        $stmt = $this->db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$this->hashPassword($newPassword), $this->userId()]);

        Response::success(null, 'Password changed successfully');
    }

    /**
     * Get user by ID (helper)
     */
    private function getUserById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT id, email, first_name, last_name, phone, avatar, role,
                   total_points, created_at, updated_at
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
