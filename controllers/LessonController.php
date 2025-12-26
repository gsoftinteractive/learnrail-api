<?php
/**
 * Lesson Controller
 */

class LessonController extends Controller {

    /**
     * Get lesson details
     * GET /api/lessons/{id}
     */
    public function show(int $id): void {
        $userId = $this->userId();

        // Get lesson with module and course info
        $stmt = $this->db->prepare("
            SELECT l.*, m.course_id, m.title as module_title,
                   c.title as course_title, c.is_free as course_is_free
            FROM lessons l
            JOIN modules m ON l.module_id = m.id
            JOIN courses c ON m.course_id = c.id
            WHERE l.id = ? AND l.is_published = 1
        ");
        $stmt->execute([$id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            Response::notFound('Lesson not found');
            return;
        }

        // Check access: either free preview, course is free, or user is enrolled
        if (!$lesson['is_free_preview'] && !$lesson['course_is_free']) {
            $stmt = $this->db->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?");
            $stmt->execute([$userId, $lesson['course_id']]);
            if (!$stmt->fetch()) {
                Response::forbidden('Enrollment required to access this lesson');
                return;
            }
        }

        // Parse attachments
        $lesson['attachments'] = json_decode($lesson['attachments'], true) ?? [];

        // Get user progress for this lesson
        $stmt = $this->db->prepare("
            SELECT status, watch_time, completed_at
            FROM lesson_progress
            WHERE user_id = ? AND lesson_id = ?
        ");
        $stmt->execute([$userId, $id]);
        $lesson['progress'] = $stmt->fetch() ?: null;

        // Get quiz if lesson type is quiz
        if ($lesson['type'] === 'quiz') {
            $stmt = $this->db->prepare("
                SELECT id, title, description, passing_score, time_limit, max_attempts
                FROM quizzes WHERE lesson_id = ?
            ");
            $stmt->execute([$id]);
            $lesson['quiz'] = $stmt->fetch();
        }

        // Get next and previous lessons
        $stmt = $this->db->prepare("
            SELECT l.id, l.title, l.type
            FROM lessons l
            JOIN modules m ON l.module_id = m.id
            WHERE m.course_id = ? AND l.is_published = 1
              AND (m.sort_order > (SELECT sort_order FROM modules WHERE id = ?)
                   OR (m.sort_order = (SELECT sort_order FROM modules WHERE id = ?) AND l.sort_order > ?))
            ORDER BY m.sort_order, l.sort_order
            LIMIT 1
        ");
        $stmt->execute([$lesson['course_id'], $lesson['module_id'], $lesson['module_id'], $lesson['sort_order']]);
        $lesson['next_lesson'] = $stmt->fetch() ?: null;

        $stmt = $this->db->prepare("
            SELECT l.id, l.title, l.type
            FROM lessons l
            JOIN modules m ON l.module_id = m.id
            WHERE m.course_id = ? AND l.is_published = 1
              AND (m.sort_order < (SELECT sort_order FROM modules WHERE id = ?)
                   OR (m.sort_order = (SELECT sort_order FROM modules WHERE id = ?) AND l.sort_order < ?))
            ORDER BY m.sort_order DESC, l.sort_order DESC
            LIMIT 1
        ");
        $stmt->execute([$lesson['course_id'], $lesson['module_id'], $lesson['module_id'], $lesson['sort_order']]);
        $lesson['prev_lesson'] = $stmt->fetch() ?: null;

        // Update last accessed
        $stmt = $this->db->prepare("UPDATE enrollments SET last_accessed_at = NOW() WHERE user_id = ? AND course_id = ?");
        $stmt->execute([$userId, $lesson['course_id']]);

        Response::success($lesson);
    }

    /**
     * Mark lesson as complete
     * POST /api/lessons/{id}/complete
     */
    public function complete(int $id): void {
        $userId = $this->userId();

        // Verify enrollment
        $stmt = $this->db->prepare("
            SELECT e.id, e.course_id, e.completed_lessons, c.total_lessons
            FROM lessons l
            JOIN modules m ON l.module_id = m.id
            JOIN enrollments e ON e.course_id = m.course_id AND e.user_id = ?
            JOIN courses c ON m.course_id = c.id
            WHERE l.id = ?
        ");
        $stmt->execute([$userId, $id]);
        $enrollment = $stmt->fetch();

        if (!$enrollment) {
            Response::forbidden('Enrollment required');
            return;
        }

        // Check if already completed
        $stmt = $this->db->prepare("SELECT id, status FROM lesson_progress WHERE user_id = ? AND lesson_id = ?");
        $stmt->execute([$userId, $id]);
        $progress = $stmt->fetch();

        if ($progress && $progress['status'] === 'completed') {
            Response::success(['message' => 'Lesson already completed']);
            return;
        }

        // Mark as complete
        if ($progress) {
            $stmt = $this->db->prepare("UPDATE lesson_progress SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmt->execute([$progress['id']]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO lesson_progress (user_id, lesson_id, status, completed_at, created_at)
                VALUES (?, ?, 'completed', NOW(), NOW())
            ");
            $stmt->execute([$userId, $id]);
        }

        // Update enrollment progress
        $completedLessons = $enrollment['completed_lessons'] + 1;
        $progressPercent = ($completedLessons / $enrollment['total_lessons']) * 100;
        $status = $progressPercent >= 100 ? 'completed' : 'in_progress';

        $stmt = $this->db->prepare("
            UPDATE enrollments
            SET completed_lessons = ?, progress_percent = ?, status = ?,
                completed_at = IF(? = 'completed', NOW(), completed_at)
            WHERE id = ?
        ");
        $stmt->execute([$completedLessons, $progressPercent, $status, $status, $enrollment['id']]);

        // Award points
        $this->awardPoints($userId, 10, 'Completed a lesson');

        // Check for course completion
        if ($status === 'completed') {
            $this->awardPoints($userId, 100, 'Completed a course');
            $this->issueCertificate($userId, $enrollment['course_id']);
        }

        Response::success([
            'completed_lessons' => $completedLessons,
            'progress_percent' => $progressPercent,
            'course_completed' => $status === 'completed'
        ]);
    }

    /**
     * Update lesson progress (for video watch time)
     * POST /api/lessons/{id}/progress
     */
    public function updateProgress(int $id): void {
        $userId = $this->userId();
        $watchTime = Request::input('watch_time', 0);

        // Upsert progress
        $stmt = $this->db->prepare("
            INSERT INTO lesson_progress (user_id, lesson_id, status, watch_time, created_at)
            VALUES (?, ?, 'in_progress', ?, NOW())
            ON DUPLICATE KEY UPDATE watch_time = GREATEST(watch_time, ?), status = IF(status = 'not_started', 'in_progress', status)
        ");
        $stmt->execute([$userId, $id, $watchTime, $watchTime]);

        Response::success(['watch_time' => $watchTime]);
    }

    /**
     * Issue certificate for completed course
     */
    private function issueCertificate(int $userId, int $courseId): void {
        $certificateNumber = 'LR-' . strtoupper(uniqid()) . '-' . date('Y');

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO certificates (user_id, course_id, certificate_number, issued_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $courseId, $certificateNumber]);
    }
}
