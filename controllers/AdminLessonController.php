<?php
/**
 * Admin Lesson Controller
 */

class AdminLessonController extends Controller {

    /**
     * Create lesson
     * POST /api/admin/modules/{moduleId}/lessons
     */
    public function create(int $moduleId): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255',
            'type' => 'required|in:video,text,quiz'
        ])) return;

        // Verify module and get course ID
        $stmt = $this->db->prepare("SELECT course_id FROM modules WHERE id = ?");
        $stmt->execute([$moduleId]);
        $module = $stmt->fetch();

        if (!$module) {
            Response::notFound('Module not found');
            return;
        }

        // Get next sort order
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM lessons WHERE module_id = ?");
        $stmt->execute([$moduleId]);
        $sortOrder = ((int) $stmt->fetchColumn()) + 1;

        $stmt = $this->db->prepare("
            INSERT INTO lessons (module_id, title, description, type, video_url, video_duration,
                content, attachments, is_free_preview, is_published, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $moduleId,
            Request::input('title'),
            Request::input('description'),
            Request::input('type'),
            Request::input('video_url'),
            Request::input('video_duration', 0),
            Request::input('content'),
            json_encode(Request::input('attachments', [])),
            Request::input('is_free_preview', false) ? 1 : 0,
            Request::input('is_published', true) ? 1 : 0,
            $sortOrder
        ]);

        $lessonId = $this->db->lastInsertId();

        // Update course lesson count
        $this->updateCourseLessonCount($module['course_id']);

        Response::created([
            'id' => $lessonId,
            'sort_order' => $sortOrder
        ], 'Lesson created');
    }

    /**
     * Update lesson
     * PUT /api/admin/lessons/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM lessons WHERE id = ?");
        $stmt->execute([$id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            Response::notFound('Lesson not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['title', 'description', 'type', 'video_url', 'video_duration',
            'content', 'is_free_preview', 'is_published', 'sort_order'];

        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        // Handle attachments
        $attachments = Request::input('attachments');
        if ($attachments !== null) {
            $updates[] = "attachments = ?";
            $params[] = json_encode($attachments);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE lessons SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Lesson updated');
    }

    /**
     * Delete lesson
     * DELETE /api/admin/lessons/{id}
     */
    public function delete(int $id): void {
        $stmt = $this->db->prepare("
            SELECT l.id, m.course_id
            FROM lessons l
            JOIN modules m ON l.module_id = m.id
            WHERE l.id = ?
        ");
        $stmt->execute([$id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            Response::notFound('Lesson not found');
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM lessons WHERE id = ?");
        $stmt->execute([$id]);

        // Update course lesson count
        $this->updateCourseLessonCount($lesson['course_id']);

        Response::success(null, 'Lesson deleted');
    }

    /**
     * Reorder lessons
     * PUT /api/admin/modules/{moduleId}/lessons/reorder
     */
    public function reorder(int $moduleId): void {
        $order = Request::input('order', []);

        foreach ($order as $index => $lessonId) {
            $stmt = $this->db->prepare("UPDATE lessons SET sort_order = ? WHERE id = ? AND module_id = ?");
            $stmt->execute([$index, $lessonId, $moduleId]);
        }

        Response::success(null, 'Lessons reordered');
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
