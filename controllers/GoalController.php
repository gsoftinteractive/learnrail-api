<?php
/**
 * Goal Controller
 */

class GoalController extends Controller {

    /**
     * List user's goals
     * GET /api/goals
     */
    public function index(): void {
        $status = Request::query('status');
        $pagination = $this->paginate();

        $where = ['user_id = ?'];
        $params = [$this->userId()];

        if ($status) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM goals WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Get goals
        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT g.*,
                   (SELECT COUNT(*) FROM milestones WHERE goal_id = g.id) as total_milestones,
                   (SELECT COUNT(*) FROM milestones WHERE goal_id = g.id AND is_completed = 1) as completed_milestones
            FROM goals g
            WHERE $whereClause
            ORDER BY g.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $goals = $stmt->fetchAll();

        Response::paginated($goals, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Create goal
     * POST /api/goals
     */
    public function create(): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255',
        ])) {
            return;
        }

        $title = Request::input('title');
        $description = Request::input('description');
        $category = Request::input('category');
        $targetDate = Request::input('target_date');
        $reminderFrequency = Request::input('reminder_frequency', 'weekly');
        $isPrivate = Request::input('is_private', true);

        $stmt = $this->db->prepare("
            INSERT INTO goals (user_id, title, description, category, target_date,
                              reminder_frequency, is_private, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $this->userId(),
            $title,
            $description,
            $category,
            $targetDate,
            $reminderFrequency,
            $isPrivate ? 1 : 0
        ]);

        $goalId = (int) $this->db->lastInsertId();

        // Create milestones if provided
        $milestones = Request::input('milestones', []);
        foreach ($milestones as $index => $milestone) {
            if (!empty($milestone['title'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO milestones (goal_id, title, description, target_date, sort_order, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $goalId,
                    $milestone['title'],
                    $milestone['description'] ?? null,
                    $milestone['target_date'] ?? null,
                    $index
                ]);
            }
        }

        // Award points for creating a goal
        $this->awardPoints($this->userId(), 5, 'Created a new goal');

        // Get created goal
        $goal = $this->getGoalById($goalId);
        Response::created($goal, 'Goal created successfully');
    }

    /**
     * Get single goal
     * GET /api/goals/{id}
     */
    public function show(int $id): void {
        $goal = $this->getGoalById($id);

        if (!$goal || $goal['user_id'] != $this->userId()) {
            Response::notFound('Goal not found');
            return;
        }

        // Get milestones
        $stmt = $this->db->prepare("
            SELECT * FROM milestones
            WHERE goal_id = ?
            ORDER BY sort_order, created_at
        ");
        $stmt->execute([$id]);
        $goal['milestones'] = $stmt->fetchAll();

        // Get recent check-ins
        $stmt = $this->db->prepare("
            SELECT * FROM goal_checkins
            WHERE goal_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $goal['checkins'] = $stmt->fetchAll();

        Response::success($goal);
    }

    /**
     * Update goal
     * PUT /api/goals/{id}
     */
    public function update(int $id): void {
        $goal = $this->getGoalById($id);

        if (!$goal || $goal['user_id'] != $this->userId()) {
            Response::notFound('Goal not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['title', 'description', 'category', 'target_date', 'reminder_frequency', 'is_private', 'status'];

        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = $field === 'is_private' ? ($value ? 1 : 0) : $value;
            }
        }

        // Handle progress update
        $progress = Request::input('progress_percent');
        if ($progress !== null) {
            $updates[] = 'progress_percent = ?';
            $params[] = min(100, max(0, (float) $progress));
        }

        if (empty($updates)) {
            Response::error('No fields to update');
            return;
        }

        // Check if completing
        $status = Request::input('status');
        if ($status === 'completed' && $goal['status'] !== 'completed') {
            $updates[] = 'completed_at = NOW()';
            // Award points
            $this->awardPoints($this->userId(), POINTS_GOAL_COMPLETE, 'Completed goal: ' . $goal['title']);
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->userId();

        $sql = "UPDATE goals SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $goal = $this->getGoalById($id);
        Response::success($goal, 'Goal updated successfully');
    }

    /**
     * Delete goal
     * DELETE /api/goals/{id}
     */
    public function delete(int $id): void {
        $stmt = $this->db->prepare("SELECT id FROM goals WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $this->userId()]);

        if (!$stmt->fetch()) {
            Response::notFound('Goal not found');
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM goals WHERE id = ?");
        $stmt->execute([$id]);

        Response::success(null, 'Goal deleted successfully');
    }

    /**
     * Check in on goal
     * POST /api/goals/{id}/checkin
     */
    public function checkin(int $id): void {
        $goal = $this->getGoalById($id);

        if (!$goal || $goal['user_id'] != $this->userId()) {
            Response::notFound('Goal not found');
            return;
        }

        $note = Request::input('note');
        $mood = Request::input('mood');
        $progressUpdate = Request::input('progress_update');

        $stmt = $this->db->prepare("
            INSERT INTO goal_checkins (goal_id, note, mood, progress_update, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$id, $note, $mood, $progressUpdate]);

        // Update goal progress if provided
        if ($progressUpdate !== null) {
            $stmt = $this->db->prepare("
                UPDATE goals SET progress_percent = ?, updated_at = NOW() WHERE id = ?
            ");
            $stmt->execute([min(100, max(0, (float) $progressUpdate)), $id]);
        }

        // Award points
        $this->awardPoints($this->userId(), 3, 'Goal check-in');

        Response::success(null, 'Check-in recorded successfully');
    }

    /**
     * Get goal by ID helper
     */
    private function getGoalById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM goals WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
