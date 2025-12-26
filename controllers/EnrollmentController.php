<?php
/**
 * Enrollment Controller
 */

class EnrollmentController extends Controller {

    /**
     * List user's enrollments
     * GET /api/enrollments
     */
    public function index(): void {
        $userId = $this->userId();
        $pagination = $this->paginate();
        $status = Request::query('status');

        $where = ['e.user_id = ?'];
        $params = [$userId];

        if ($status) {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM enrollments e WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Get enrollments
        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT e.id, e.progress_percent, e.status, e.completed_lessons,
                   e.last_accessed_at, e.enrolled_at, e.completed_at,
                   c.id as course_id, c.title, c.slug, c.thumbnail, c.total_lessons,
                   c.duration_hours, c.level,
                   i.name as instructor_name, i.avatar as instructor_avatar
            FROM enrollments e
            JOIN courses c ON e.course_id = c.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE $whereClause
            ORDER BY e.last_accessed_at DESC, e.enrolled_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $enrollments = $stmt->fetchAll();

        Response::paginated($enrollments, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Enroll in a course
     * POST /api/courses/{id}/enroll
     */
    public function enroll(int $courseId): void {
        $userId = $this->userId();

        // Check if course exists
        $stmt = $this->db->prepare("SELECT id, is_free, price FROM courses WHERE id = ? AND is_published = 1");
        $stmt->execute([$courseId]);
        $course = $stmt->fetch();

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        // Check if already enrolled
        $stmt = $this->db->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?");
        $stmt->execute([$userId, $courseId]);
        if ($stmt->fetch()) {
            Response::error('Already enrolled in this course', 400);
            return;
        }

        // Check subscription access for paid courses
        if (!$course['is_free']) {
            $subscription = $this->subscription();
            if (!$subscription) {
                Response::forbidden('Active subscription required for this course');
                return;
            }
        }

        // Create enrollment
        $stmt = $this->db->prepare("
            INSERT INTO enrollments (user_id, course_id, status, enrolled_at)
            VALUES (?, ?, 'enrolled', NOW())
        ");
        $stmt->execute([$userId, $courseId]);
        $enrollmentId = $this->db->lastInsertId();

        // Update course enrollment count
        $stmt = $this->db->prepare("UPDATE courses SET total_enrollments = total_enrollments + 1 WHERE id = ?");
        $stmt->execute([$courseId]);

        // Award points
        $this->awardPoints($userId, 5, 'Enrolled in a course');

        Response::created(['enrollment_id' => $enrollmentId], 'Successfully enrolled');
    }

    /**
     * Get course progress
     * GET /api/courses/{id}/progress
     */
    public function progress(int $courseId): void {
        $userId = $this->userId();

        // Get enrollment
        $stmt = $this->db->prepare("
            SELECT e.*, c.total_lessons
            FROM enrollments e
            JOIN courses c ON e.course_id = c.id
            WHERE e.user_id = ? AND e.course_id = ?
        ");
        $stmt->execute([$userId, $courseId]);
        $enrollment = $stmt->fetch();

        if (!$enrollment) {
            Response::notFound('Enrollment not found');
            return;
        }

        // Get completed lessons
        $stmt = $this->db->prepare("
            SELECT lp.lesson_id, lp.status, lp.watch_time, lp.completed_at,
                   l.title, l.type, l.video_duration, m.title as module_title
            FROM lesson_progress lp
            JOIN lessons l ON lp.lesson_id = l.id
            JOIN modules m ON l.module_id = m.id
            WHERE lp.user_id = ? AND m.course_id = ?
            ORDER BY m.sort_order, l.sort_order
        ");
        $stmt->execute([$userId, $courseId]);
        $lessonProgress = $stmt->fetchAll();

        // Get quiz attempts
        $stmt = $this->db->prepare("
            SELECT qa.quiz_id, qa.score, qa.passed, qa.created_at,
                   q.title, q.passing_score
            FROM quiz_attempts qa
            JOIN quizzes q ON qa.quiz_id = q.id
            JOIN lessons l ON q.lesson_id = l.id
            JOIN modules m ON l.module_id = m.id
            WHERE qa.user_id = ? AND m.course_id = ?
            ORDER BY qa.created_at DESC
        ");
        $stmt->execute([$userId, $courseId]);
        $quizAttempts = $stmt->fetchAll();

        Response::success([
            'enrollment' => $enrollment,
            'lesson_progress' => $lessonProgress,
            'quiz_attempts' => $quizAttempts
        ]);
    }
}
