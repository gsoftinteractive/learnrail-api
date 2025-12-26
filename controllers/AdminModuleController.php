<?php
/**
 * Admin Module Controller
 */

class AdminModuleController extends Controller {

    /**
     * Create module
     * POST /api/admin/courses/{courseId}/modules
     */
    public function create(int $courseId): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255'
        ])) return;

        // Verify course exists
        $stmt = $this->db->prepare("SELECT id FROM courses WHERE id = ?");
        $stmt->execute([$courseId]);
        if (!$stmt->fetch()) {
            Response::notFound('Course not found');
            return;
        }

        // Get next sort order
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM modules WHERE course_id = ?");
        $stmt->execute([$courseId]);
        $sortOrder = ((int) $stmt->fetchColumn()) + 1;

        $stmt = $this->db->prepare("
            INSERT INTO modules (course_id, title, description, sort_order, is_published, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $courseId,
            Request::input('title'),
            Request::input('description'),
            $sortOrder,
            Request::input('is_published', true) ? 1 : 0
        ]);

        Response::created([
            'id' => $this->db->lastInsertId(),
            'sort_order' => $sortOrder
        ], 'Module created');
    }

    /**
     * Update module
     * PUT /api/admin/modules/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM modules WHERE id = ?");
        $stmt->execute([$id]);
        $module = $stmt->fetch();

        if (!$module) {
            Response::notFound('Module not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['title', 'description', 'sort_order', 'is_published'];
        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE modules SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Module updated');
    }

    /**
     * Delete module
     * DELETE /api/admin/modules/{id}
     */
    public function delete(int $id): void {
        $stmt = $this->db->prepare("SELECT course_id FROM modules WHERE id = ?");
        $stmt->execute([$id]);
        $module = $stmt->fetch();

        if (!$module) {
            Response::notFound('Module not found');
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM modules WHERE id = ?");
        $stmt->execute([$id]);

        // Update course lesson count
        $this->updateCourseLessonCount($module['course_id']);

        Response::success(null, 'Module deleted');
    }

    /**
     * Reorder modules
     * PUT /api/admin/courses/{courseId}/modules/reorder
     */
    public function reorder(int $courseId): void {
        $order = Request::input('order', []);

        foreach ($order as $index => $moduleId) {
            $stmt = $this->db->prepare("UPDATE modules SET sort_order = ? WHERE id = ? AND course_id = ?");
            $stmt->execute([$index, $moduleId, $courseId]);
        }

        Response::success(null, 'Modules reordered');
    }

    private function updateCourseLessonCount(int $courseId): void {
        $stmt = $this->db->prepare("
            SELECT COUNT(l.id) FROM lessons l
            JOIN modules m ON l.module_id = m.id
            WHERE m.course_id = ?
        ");
        $stmt->execute([$courseId]);
        $count = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("UPDATE courses SET total_lessons = ? WHERE id = ?");
        $stmt->execute([$count, $courseId]);
    }
}
