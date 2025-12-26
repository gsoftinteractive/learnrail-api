<?php
/**
 * Admin Course Controller
 */

class AdminCourseController extends Controller {

    /**
     * List all courses
     * GET /api/admin/courses
     */
    public function index(): void {
        $pagination = $this->paginate();
        $search = Request::query('search');
        $category = Request::query('category');
        $published = Request::query('published');

        $where = ['1=1'];
        $params = [];

        if ($search) {
            $where[] = '(c.title LIKE ? OR c.description LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($category) {
            $where[] = 'c.category_id = ?';
            $params[] = $category;
        }

        if ($published !== null) {
            $where[] = 'c.is_published = ?';
            $params[] = $published === 'true' ? 1 : 0;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM courses c WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT c.*, cat.name as category_name, i.name as instructor_name
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE $whereClause
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $courses = $stmt->fetchAll();

        Response::paginated($courses, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Get single course
     * GET /api/admin/courses/{id}
     */
    public function show(int $id): void {
        $stmt = $this->db->prepare("
            SELECT c.*, cat.name as category_name, i.name as instructor_name
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
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
        $stmt = $this->db->prepare("SELECT * FROM modules WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $modules = $stmt->fetchAll();

        foreach ($modules as &$module) {
            $stmt = $this->db->prepare("SELECT * FROM lessons WHERE module_id = ? ORDER BY sort_order");
            $stmt->execute([$module['id']]);
            $module['lessons'] = $stmt->fetchAll();
        }

        $course['modules'] = $modules;

        Response::success($course);
    }

    /**
     * Create course
     * POST /api/admin/courses
     */
    public function create(): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255',
            'level' => 'required|in:beginner,intermediate,advanced'
        ])) return;

        $title = Request::input('title');
        $slug = $this->generateSlug($title, 'courses');

        $stmt = $this->db->prepare("
            INSERT INTO courses (title, slug, description, short_description, thumbnail,
                preview_video_url, instructor_id, category_id, level, language,
                duration_hours, price, is_free, is_featured, requirements, what_you_learn, tags, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $title,
            $slug,
            Request::input('description'),
            Request::input('short_description'),
            Request::input('thumbnail'),
            Request::input('preview_video_url'),
            Request::input('instructor_id'),
            Request::input('category_id'),
            Request::input('level'),
            Request::input('language', 'English'),
            Request::input('duration_hours', 0),
            Request::input('price', 0),
            Request::input('is_free', false) ? 1 : 0,
            Request::input('is_featured', false) ? 1 : 0,
            json_encode(Request::input('requirements', [])),
            json_encode(Request::input('what_you_learn', [])),
            json_encode(Request::input('tags', []))
        ]);

        Response::created(['id' => $this->db->lastInsertId(), 'slug' => $slug], 'Course created');
    }

    /**
     * Update course
     * PUT /api/admin/courses/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM courses WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Course not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['title', 'description', 'short_description', 'thumbnail', 'preview_video_url',
            'instructor_id', 'category_id', 'level', 'language', 'duration_hours', 'price',
            'is_free', 'is_featured', 'is_published'];

        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        // Handle JSON fields
        $jsonFields = ['requirements', 'what_you_learn', 'tags'];
        foreach ($jsonFields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = json_encode($value);
            }
        }

        // Update slug if title changed
        $newTitle = Request::input('title');
        if ($newTitle) {
            $updates[] = "slug = ?";
            $params[] = $this->generateSlug($newTitle, 'courses');
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE courses SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Course updated');
    }

    /**
     * Delete course
     * DELETE /api/admin/courses/{id}
     */
    public function delete(int $id): void {
        $stmt = $this->db->prepare("DELETE FROM courses WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Course not found');
            return;
        }

        Response::success(null, 'Course deleted');
    }

    /**
     * Update lesson count
     */
    private function updateLessonCount(int $courseId): void {
        $stmt = $this->db->prepare("
            SELECT COUNT(l.id)
            FROM lessons l
            JOIN modules m ON l.module_id = m.id
            WHERE m.course_id = ?
        ");
        $stmt->execute([$courseId]);
        $count = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("UPDATE courses SET total_lessons = ? WHERE id = ?");
        $stmt->execute([$count, $courseId]);
    }
}
