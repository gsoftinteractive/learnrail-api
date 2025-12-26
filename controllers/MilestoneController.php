<?php
/**
 * Milestone Controller
 */

class MilestoneController extends Controller {

    /**
     * Create milestone for goal
     * POST /api/goals/{goalId}/milestones
     */
    public function create(int $goalId): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255'
        ])) return;

        $userId = $this->userId();

        // Verify goal ownership
        $stmt = $this->db->prepare("SELECT id FROM goals WHERE id = ? AND user_id = ?");
        $stmt->execute([$goalId, $userId]);
        if (!$stmt->fetch()) {
            Response::notFound('Goal not found');
            return;
        }

        $title = Request::input('title');
        $description = Request::input('description');
        $targetDate = Request::input('target_date');

        // Get next sort order
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM milestones WHERE goal_id = ?");
        $stmt->execute([$goalId]);
        $sortOrder = ((int) $stmt->fetchColumn()) + 1;

        $stmt = $this->db->prepare("
            INSERT INTO milestones (goal_id, title, description, target_date, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$goalId, $title, $description, $targetDate, $sortOrder]);
        $milestoneId = $this->db->lastInsertId();

        Response::created([
            'id' => $milestoneId,
            'title' => $title,
            'sort_order' => $sortOrder
        ], 'Milestone created');
    }

    /**
     * Update milestone
     * PUT /api/milestones/{id}
     */
    public function update(int $id): void {
        $userId = $this->userId();

        // Get milestone with ownership check
        $stmt = $this->db->prepare("
            SELECT m.*, g.user_id
            FROM milestones m
            JOIN goals g ON m.goal_id = g.id
            WHERE m.id = ? AND g.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        $milestone = $stmt->fetch();

        if (!$milestone) {
            Response::notFound('Milestone not found');
            return;
        }

        $title = Request::input('title', $milestone['title']);
        $description = Request::input('description', $milestone['description']);
        $targetDate = Request::input('target_date', $milestone['target_date']);

        $stmt = $this->db->prepare("
            UPDATE milestones SET title = ?, description = ?, target_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$title, $description, $targetDate, $id]);

        Response::success(['id' => $id], 'Milestone updated');
    }

    /**
     * Delete milestone
     * DELETE /api/milestones/{id}
     */
    public function delete(int $id): void {
        $userId = $this->userId();

        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT m.goal_id
            FROM milestones m
            JOIN goals g ON m.goal_id = g.id
            WHERE m.id = ? AND g.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        $milestone = $stmt->fetch();

        if (!$milestone) {
            Response::notFound('Milestone not found');
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM milestones WHERE id = ?");
        $stmt->execute([$id]);

        // Recalculate goal progress
        $this->recalculateGoalProgress($milestone['goal_id']);

        Response::success(null, 'Milestone deleted');
    }

    /**
     * Mark milestone as complete
     * POST /api/milestones/{id}/complete
     */
    public function complete(int $id): void {
        $userId = $this->userId();

        // Get milestone with ownership check
        $stmt = $this->db->prepare("
            SELECT m.*, g.user_id
            FROM milestones m
            JOIN goals g ON m.goal_id = g.id
            WHERE m.id = ? AND g.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        $milestone = $stmt->fetch();

        if (!$milestone) {
            Response::notFound('Milestone not found');
            return;
        }

        if ($milestone['is_completed']) {
            Response::success(['message' => 'Milestone already completed']);
            return;
        }

        // Mark as complete
        $stmt = $this->db->prepare("
            UPDATE milestones SET is_completed = 1, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        // Recalculate goal progress
        $newProgress = $this->recalculateGoalProgress($milestone['goal_id']);

        // Award points
        $this->awardPoints($userId, 15, 'Completed a milestone');

        Response::success([
            'milestone_completed' => true,
            'goal_progress' => $newProgress
        ], 'Milestone completed');
    }

    /**
     * Reorder milestones
     * PUT /api/goals/{goalId}/milestones/reorder
     */
    public function reorder(int $goalId): void {
        $userId = $this->userId();
        $order = Request::input('order', []);

        // Verify goal ownership
        $stmt = $this->db->prepare("SELECT id FROM goals WHERE id = ? AND user_id = ?");
        $stmt->execute([$goalId, $userId]);
        if (!$stmt->fetch()) {
            Response::notFound('Goal not found');
            return;
        }

        foreach ($order as $index => $milestoneId) {
            $stmt = $this->db->prepare("
                UPDATE milestones SET sort_order = ?
                WHERE id = ? AND goal_id = ?
            ");
            $stmt->execute([$index, $milestoneId, $goalId]);
        }

        Response::success(null, 'Milestones reordered');
    }

    /**
     * Recalculate goal progress based on milestones
     */
    private function recalculateGoalProgress(int $goalId): float {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total, SUM(is_completed) as completed
            FROM milestones WHERE goal_id = ?
        ");
        $stmt->execute([$goalId]);
        $stats = $stmt->fetch();

        $progress = $stats['total'] > 0
            ? round(($stats['completed'] / $stats['total']) * 100, 2)
            : 0;

        // Update goal progress
        $status = $progress >= 100 ? 'completed' : 'active';
        $stmt = $this->db->prepare("
            UPDATE goals
            SET progress_percent = ?, status = ?,
                completed_at = IF(? = 'completed', NOW(), completed_at)
            WHERE id = ?
        ");
        $stmt->execute([$progress, $status, $status, $goalId]);

        return $progress;
    }
}
