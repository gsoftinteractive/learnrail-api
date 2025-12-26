<?php
/**
 * Gamification Controller
 */

class GamificationController extends Controller {

    /**
     * Get leaderboard
     * GET /api/leaderboard
     */
    public function leaderboard(): void {
        $type = Request::query('type', 'all_time'); // all_time, weekly, monthly
        $limit = min(100, max(10, (int) Request::query('limit', 50)));

        $dateFilter = '';
        if ($type === 'weekly') {
            $dateFilter = 'AND pt.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        } elseif ($type === 'monthly') {
            $dateFilter = 'AND pt.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }

        if ($type === 'all_time') {
            $stmt = $this->db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.avatar, u.total_points,
                       u.current_streak, u.longest_streak,
                       (SELECT COUNT(*) FROM certificates WHERE user_id = u.id) as courses_completed
                FROM users u
                WHERE u.status = 'active' AND u.role = 'user'
                ORDER BY u.total_points DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.avatar,
                       COALESCE(SUM(pt.points), 0) as period_points,
                       u.current_streak
                FROM users u
                LEFT JOIN points_transactions pt ON u.id = pt.user_id $dateFilter
                WHERE u.status = 'active' AND u.role = 'user'
                GROUP BY u.id
                ORDER BY period_points DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }

        $leaderboard = $stmt->fetchAll();

        // Add rank
        foreach ($leaderboard as $index => &$entry) {
            $entry['rank'] = $index + 1;
        }

        // Get current user's rank if authenticated
        $userId = $this->userId();
        $userRank = null;

        if ($userId) {
            $stmt = $this->db->prepare("SELECT total_points FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userPoints = (int) $stmt->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT COUNT(*) + 1 FROM users
                WHERE total_points > ? AND status = 'active' AND role = 'user'
            ");
            $stmt->execute([$userPoints]);
            $userRank = (int) $stmt->fetchColumn();
        }

        Response::success([
            'leaderboard' => $leaderboard,
            'type' => $type,
            'user_rank' => $userRank
        ]);
    }

    /**
     * Get user's badges
     * GET /api/badges
     */
    public function badges(): void {
        $userId = $this->userId();

        // Get all badges with user's status
        $stmt = $this->db->prepare("
            SELECT b.id, b.name, b.slug, b.description, b.icon, b.points_required,
                   ub.earned_at,
                   IF(ub.id IS NOT NULL, TRUE, FALSE) as earned
            FROM badges b
            LEFT JOIN user_badges ub ON b.id = ub.badge_id AND ub.user_id = ?
            WHERE b.is_active = 1
            ORDER BY b.points_required, b.name
        ");
        $stmt->execute([$userId]);
        $badges = $stmt->fetchAll();

        // Convert earned to boolean
        foreach ($badges as &$badge) {
            $badge['earned'] = (bool) $badge['earned'];
        }

        Response::success($badges);
    }

    /**
     * Get user's achievements
     * GET /api/achievements
     */
    public function achievements(): void {
        $userId = $this->userId();

        // Get all achievements with user's progress
        $stmt = $this->db->prepare("
            SELECT a.id, a.name, a.slug, a.description, a.icon,
                   a.type, a.target_value, a.points_reward,
                   COALESCE(ua.current_value, 0) as current_value,
                   COALESCE(ua.is_completed, FALSE) as is_completed,
                   ua.completed_at
            FROM achievements a
            LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.user_id = ?
            WHERE a.is_active = 1
            ORDER BY a.points_reward DESC
        ");
        $stmt->execute([$userId]);
        $achievements = $stmt->fetchAll();

        // Calculate progress percentage
        foreach ($achievements as &$achievement) {
            $achievement['is_completed'] = (bool) $achievement['is_completed'];
            $achievement['progress_percent'] = min(100, round(($achievement['current_value'] / $achievement['target_value']) * 100));
        }

        Response::success($achievements);
    }

    /**
     * Get user's points history
     * GET /api/points-history
     */
    public function pointsHistory(): void {
        $userId = $this->userId();
        $pagination = $this->paginate();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM points_transactions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT id, points, reason, reference_type, reference_id, created_at
            FROM points_transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $pagination['per_page'], $pagination['offset']]);
        $history = $stmt->fetchAll();

        // Get total points
        $stmt = $this->db->prepare("SELECT total_points FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $totalPoints = (int) $stmt->fetchColumn();

        Response::paginated($history, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Get user's gamification profile
     * GET /api/gamification/profile
     */
    public function profile(): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            SELECT total_points, current_streak, longest_streak
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Count badges earned
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_badges WHERE user_id = ?");
        $stmt->execute([$userId]);
        $badgesEarned = (int) $stmt->fetchColumn();

        // Count achievements completed
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = ? AND is_completed = 1");
        $stmt->execute([$userId]);
        $achievementsCompleted = (int) $stmt->fetchColumn();

        // Count courses completed
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM certificates WHERE user_id = ?");
        $stmt->execute([$userId]);
        $coursesCompleted = (int) $stmt->fetchColumn();

        // Calculate level (every 500 points = 1 level)
        $level = floor($user['total_points'] / 500) + 1;
        $pointsToNextLevel = (($level) * 500) - $user['total_points'];

        Response::success([
            'total_points' => $user['total_points'],
            'current_streak' => $user['current_streak'],
            'longest_streak' => $user['longest_streak'],
            'level' => $level,
            'points_to_next_level' => $pointsToNextLevel,
            'badges_earned' => $badgesEarned,
            'achievements_completed' => $achievementsCompleted,
            'courses_completed' => $coursesCompleted
        ]);
    }
}
