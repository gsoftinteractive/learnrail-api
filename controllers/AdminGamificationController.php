<?php
/**
 * Admin Gamification Controller
 */

class AdminGamificationController extends Controller {

    /**
     * List all badges
     * GET /api/admin/badges
     */
    public function badges(): void {
        $stmt = $this->db->query("
            SELECT b.*,
                   (SELECT COUNT(*) FROM user_badges WHERE badge_id = b.id) as earned_count
            FROM badges b
            ORDER BY b.points_required, b.name
        ");
        $badges = $stmt->fetchAll();

        foreach ($badges as &$badge) {
            $badge['criteria'] = json_decode($badge['criteria'], true);
        }

        Response::success($badges);
    }

    /**
     * Create badge
     * POST /api/admin/badges
     */
    public function createBadge(): void {
        if (!$this->validate([
            'name' => 'required|min:2|max:100'
        ])) return;

        $name = Request::input('name');
        $slug = $this->generateSlug($name, 'badges');

        $stmt = $this->db->prepare("
            INSERT INTO badges (name, slug, description, icon, points_required, criteria, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $name,
            $slug,
            Request::input('description'),
            Request::input('icon'),
            Request::input('points_required', 0),
            json_encode(Request::input('criteria', [])),
            Request::input('is_active', true) ? 1 : 0
        ]);

        Response::created(['id' => $this->db->lastInsertId(), 'slug' => $slug], 'Badge created');
    }

    /**
     * Update badge
     * PUT /api/admin/badges/{id}
     */
    public function updateBadge(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM badges WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Badge not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['name', 'description', 'icon', 'points_required', 'is_active'];
        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        $criteria = Request::input('criteria');
        if ($criteria !== null) {
            $updates[] = "criteria = ?";
            $params[] = json_encode($criteria);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE badges SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Badge updated');
    }

    /**
     * List all achievements
     * GET /api/admin/achievements
     */
    public function achievements(): void {
        $stmt = $this->db->query("
            SELECT a.*,
                   (SELECT COUNT(*) FROM user_achievements WHERE achievement_id = a.id AND is_completed = 1) as completed_count
            FROM achievements a
            ORDER BY a.points_reward DESC
        ");
        Response::success($stmt->fetchAll());
    }

    /**
     * Create achievement
     * POST /api/admin/achievements
     */
    public function createAchievement(): void {
        if (!$this->validate([
            'name' => 'required|min:2|max:100',
            'type' => 'required',
            'target_value' => 'required|integer'
        ])) return;

        $name = Request::input('name');
        $slug = $this->generateSlug($name, 'achievements');

        $stmt = $this->db->prepare("
            INSERT INTO achievements (name, slug, description, icon, type, target_value, points_reward, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $name,
            $slug,
            Request::input('description'),
            Request::input('icon'),
            Request::input('type'),
            Request::input('target_value'),
            Request::input('points_reward', 0),
            Request::input('is_active', true) ? 1 : 0
        ]);

        Response::created(['id' => $this->db->lastInsertId(), 'slug' => $slug], 'Achievement created');
    }

    /**
     * Award badge to user
     * POST /api/admin/badges/{id}/award
     */
    public function awardBadge(int $badgeId): void {
        $userId = Request::input('user_id');

        if (!$userId) {
            Response::validationError(['user_id' => 'User ID is required']);
            return;
        }

        // Check if already awarded
        $stmt = $this->db->prepare("SELECT id FROM user_badges WHERE user_id = ? AND badge_id = ?");
        $stmt->execute([$userId, $badgeId]);
        if ($stmt->fetch()) {
            Response::error('User already has this badge', 400);
            return;
        }

        $stmt = $this->db->prepare("INSERT INTO user_badges (user_id, badge_id, earned_at) VALUES (?, ?, NOW())");
        $stmt->execute([$userId, $badgeId]);

        // Notify user
        $stmt = $this->db->prepare("SELECT name FROM badges WHERE id = ?");
        $stmt->execute([$badgeId]);
        $badge = $stmt->fetch();

        NotificationController::create(
            $this->db,
            $userId,
            'Badge Earned!',
            "Congratulations! You've earned the {$badge['name']} badge!",
            'achievement',
            ['badge_id' => $badgeId]
        );

        Response::success(null, 'Badge awarded');
    }
}
