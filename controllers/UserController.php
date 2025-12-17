<?php
/**
 * User Controller
 */

class UserController extends Controller {

    /**
     * Get user profile
     * GET /api/profile
     */
    public function profile(): void {
        $user = $this->user();

        if (!$user) {
            Response::notFound('User not found');
            return;
        }

        // Get stats
        $stats = $this->getUserStats($this->userId());
        $user['stats'] = $stats;

        // Get active subscription
        $stmt = $this->db->prepare("
            SELECT s.*, sp.name as plan_name, sp.slug as plan_slug,
                   sp.includes_goal_tracker, sp.includes_accountability_partner
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active' AND s.end_date > NOW()
            ORDER BY s.end_date DESC
            LIMIT 1
        ");
        $stmt->execute([$this->userId()]);
        $user['subscription'] = $stmt->fetch() ?: null;

        // Get recent badges
        $stmt = $this->db->prepare("
            SELECT b.name, b.slug, b.icon, ub.earned_at
            FROM user_badges ub
            JOIN badges b ON ub.badge_id = b.id
            WHERE ub.user_id = ?
            ORDER BY ub.earned_at DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId()]);
        $user['recent_badges'] = $stmt->fetchAll();

        Response::success($user);
    }

    /**
     * Update user profile
     * PUT /api/profile
     */
    public function updateProfile(): void {
        $firstName = Request::input('first_name');
        $lastName = Request::input('last_name');
        $phone = Request::input('phone');

        $updates = [];
        $params = [];

        if ($firstName !== null) {
            $updates[] = 'first_name = ?';
            $params[] = $firstName;
        }

        if ($lastName !== null) {
            $updates[] = 'last_name = ?';
            $params[] = $lastName;
        }

        if ($phone !== null) {
            $updates[] = 'phone = ?';
            $params[] = $phone;
        }

        if (empty($updates)) {
            Response::error('No fields to update');
            return;
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $this->userId();

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $user = $this->user();
        Response::success($user, 'Profile updated successfully');
    }

    /**
     * Upload avatar
     * POST /api/profile/avatar
     */
    public function uploadAvatar(): void {
        $file = Request::file('avatar');

        if (!$file) {
            Response::error('No file uploaded');
            return;
        }

        // Validate file type
        if (!in_array($file['type'], ALLOWED_IMAGE_TYPES)) {
            Response::error('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
            return;
        }

        // Validate file size
        if ($file['size'] > MAX_FILE_SIZE) {
            Response::error('File too large. Maximum size: 10MB');
            return;
        }

        // Delete old avatar
        $user = $this->user();
        if ($user['avatar']) {
            $this->deleteFile($user['avatar']);
        }

        // Upload new avatar
        $path = $this->uploadFile($file, 'avatars');

        if (!$path) {
            Response::error('Failed to upload file');
            return;
        }

        // Update user
        $stmt = $this->db->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$path, $this->userId()]);

        Response::success(['avatar' => $path], 'Avatar updated successfully');
    }

    /**
     * Get user stats helper
     */
    private function getUserStats(int $userId): array {
        // Enrolled courses
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ?");
        $stmt->execute([$userId]);
        $enrolledCourses = (int) $stmt->fetchColumn();

        // Completed courses
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $completedCourses = (int) $stmt->fetchColumn();

        // Certificates
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM certificates WHERE user_id = ?");
        $stmt->execute([$userId]);
        $certificates = (int) $stmt->fetchColumn();

        // Completed lessons
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $completedLessons = (int) $stmt->fetchColumn();

        // Active goals
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM goals WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $activeGoals = (int) $stmt->fetchColumn();

        // Completed goals
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM goals WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $completedGoals = (int) $stmt->fetchColumn();

        // Badges
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_badges WHERE user_id = ?");
        $stmt->execute([$userId]);
        $badges = (int) $stmt->fetchColumn();

        return [
            'enrolled_courses' => $enrolledCourses,
            'completed_courses' => $completedCourses,
            'certificates' => $certificates,
            'completed_lessons' => $completedLessons,
            'active_goals' => $activeGoals,
            'completed_goals' => $completedGoals,
            'badges' => $badges,
        ];
    }
}
