<?php
/**
 * Course Controller
 */

class CourseController extends Controller {

    /**
     * List courses
     * GET /api/courses
     */
    public function index(): void {
        $pagination = $this->paginate();
        $search = Request::query('search');
        $category = Request::query('category');
        $level = Request::query('level');
        $featured = Request::query('featured');

        $where = ['c.is_published = 1'];
        $params = [];

        if ($search) {
            $where[] = '(c.title LIKE ? OR c.description LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($category) {
            $where[] = 'cat.slug = ?';
            $params[] = $category;
        }

        if ($level) {
            $where[] = 'c.level = ?';
            $params[] = $level;
        }

        if ($featured === 'true') {
            $where[] = 'c.is_featured = 1';
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT c.id)
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            WHERE $whereClause
        ");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Get courses
        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.slug, c.short_description, c.thumbnail,
                   c.level, c.duration_hours, c.total_lessons, c.price, c.is_free,
                   c.is_featured, c.rating, c.total_reviews, c.total_enrollments,
                   cat.name as category_name, cat.slug as category_slug,
                   i.name as instructor_name, i.avatar as instructor_avatar
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE $whereClause
            ORDER BY c.is_featured DESC, c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $courses = $stmt->fetchAll();

        Response::paginated($courses, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Get single course
     * GET /api/courses/{slug}
     */
    public function show(string $slug): void {
        $stmt = $this->db->prepare("
            SELECT c.*, cat.name as category_name, cat.slug as category_slug,
                   i.id as instructor_id, i.name as instructor_name,
                   i.bio as instructor_bio, i.avatar as instructor_avatar, i.title as instructor_title
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE c.slug = ? AND c.is_published = 1
        ");
        $stmt->execute([$slug]);
        $course = $stmt->fetch();

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        // Parse JSON fields
        $course['requirements'] = json_decode($course['requirements'], true) ?? [];
        $course['what_you_learn'] = json_decode($course['what_you_learn'], true) ?? [];
        $course['tags'] = json_decode($course['tags'], true) ?? [];

        // Get modules with lessons
        $stmt = $this->db->prepare("
            SELECT id, title, description, sort_order
            FROM modules
            WHERE course_id = ? AND is_published = 1
            ORDER BY sort_order
        ");
        $stmt->execute([$course['id']]);
        $modules = $stmt->fetchAll();

        foreach ($modules as &$module) {
            $stmt = $this->db->prepare("
                SELECT id, title, type, video_duration, is_free_preview, sort_order
                FROM lessons
                WHERE module_id = ? AND is_published = 1
                ORDER BY sort_order
            ");
            $stmt->execute([$module['id']]);
            $module['lessons'] = $stmt->fetchAll();
        }

        $course['modules'] = $modules;

        // Check enrollment if user is authenticated
        $userId = $GLOBALS['auth_user_id'] ?? null;
        if ($userId) {
            $stmt = $this->db->prepare("
                SELECT id, progress_percent, status, completed_lessons
                FROM enrollments
                WHERE user_id = ? AND course_id = ?
            ");
            $stmt->execute([$userId, $course['id']]);
            $course['enrollment'] = $stmt->fetch() ?: null;
        }

        // Get reviews
        $stmt = $this->db->prepare("
            SELECT r.rating, r.comment, r.created_at,
                   u.first_name, u.last_name, u.avatar
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            WHERE r.course_id = ? AND r.is_approved = 1
            ORDER BY r.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$course['id']]);
        $course['reviews'] = $stmt->fetchAll();

        Response::success($course);
    }
}
