<?php
/**
 * Admin AI Course Controller
 * Manages AI-taught courses with curriculum
 */

class AdminAiCourseController extends Controller {

    /**
     * List all AI courses
     * GET /api/admin/ai-courses
     */
    public function index(): void {
        $pagination = $this->paginate();
        $search = Request::query('search');
        $status = Request::query('status'); // published, draft

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (title LIKE ? OR description LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($status === 'published') {
            $where .= " AND is_published = 1";
        } elseif ($status === 'draft') {
            $where .= " AND is_published = 0";
        }

        $stmt = $this->db->prepare("
            SELECT c.*, cat.name as category_name,
                   (SELECT COUNT(*) FROM ai_modules WHERE course_id = c.id) as modules_count,
                   (SELECT COUNT(*) FROM ai_enrollments WHERE course_id = c.id) as enrollments_count
            FROM ai_courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            WHERE {$where}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];
        $stmt->execute($params);
        $courses = $stmt->fetchAll();

        // Get total
        $countParams = array_slice($params, 0, -2);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ai_courses c WHERE {$where}");
        $stmt->execute($countParams);
        $total = (int) $stmt->fetchColumn();

        foreach ($courses as &$course) {
            $course['tags'] = json_decode($course['tags'], true);
            $course['learning_outcomes'] = json_decode($course['learning_outcomes'], true);
            $course['prerequisites'] = json_decode($course['prerequisites'], true);
        }

        Response::paginated($courses, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Get single AI course with modules and lessons
     * GET /api/admin/ai-courses/{id}
     */
    public function show(int $id): void {
        $stmt = $this->db->prepare("
            SELECT c.*, cat.name as category_name
            FROM ai_courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $course = $stmt->fetch();

        if (!$course) {
            Response::error('Course not found', 404);
            return;
        }

        // Get modules with lessons
        $stmt = $this->db->prepare("
            SELECT m.id as module_id, m.title as module_title, m.description as module_description,
                   m.sort_order as module_order, m.is_published as module_published,
                   l.id as lesson_id, l.title as lesson_title, l.description as lesson_description,
                   l.objectives, l.key_concepts, l.teaching_notes, l.estimated_minutes,
                   l.difficulty, l.sort_order as lesson_order, l.is_published as lesson_published
            FROM ai_modules m
            LEFT JOIN ai_lessons l ON l.module_id = m.id
            WHERE m.course_id = ?
            ORDER BY m.sort_order, l.sort_order
        ");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();

        $modules = [];
        foreach ($rows as $row) {
            $moduleId = $row['module_id'];
            if (!isset($modules[$moduleId])) {
                $modules[$moduleId] = [
                    'id' => $moduleId,
                    'title' => $row['module_title'],
                    'description' => $row['module_description'],
                    'sort_order' => $row['module_order'],
                    'is_published' => (bool) $row['module_published'],
                    'lessons' => []
                ];
            }
            if ($row['lesson_id']) {
                $modules[$moduleId]['lessons'][] = [
                    'id' => $row['lesson_id'],
                    'title' => $row['lesson_title'],
                    'description' => $row['lesson_description'],
                    'objectives' => json_decode($row['objectives'], true),
                    'key_concepts' => json_decode($row['key_concepts'], true),
                    'teaching_notes' => $row['teaching_notes'],
                    'estimated_minutes' => $row['estimated_minutes'],
                    'difficulty' => $row['difficulty'],
                    'sort_order' => $row['lesson_order'],
                    'is_published' => (bool) $row['lesson_published']
                ];
            }
        }

        $course['tags'] = json_decode($course['tags'], true);
        $course['learning_outcomes'] = json_decode($course['learning_outcomes'], true);
        $course['prerequisites'] = json_decode($course['prerequisites'], true);
        $course['modules'] = array_values($modules);

        Response::success($course);
    }

    /**
     * Create new AI course
     * POST /api/admin/ai-courses
     */
    public function create(): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255',
            'description' => 'required|min:10'
        ])) return;

        $title = Request::input('title');
        $slug = $this->generateSlug($title);

        $stmt = $this->db->prepare("
            INSERT INTO ai_courses (
                title, slug, description, short_description, thumbnail,
                category_id, level, language, is_free, is_featured, is_published,
                tags, prerequisites, learning_outcomes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $title,
            $slug,
            Request::input('description'),
            Request::input('short_description'),
            Request::input('thumbnail'),
            Request::input('category_id'),
            Request::input('level', 'beginner'),
            Request::input('language', 'English'),
            Request::input('is_free', false) ? 1 : 0,
            Request::input('is_featured', false) ? 1 : 0,
            Request::input('is_published', false) ? 1 : 0,
            json_encode(Request::input('tags', [])),
            json_encode(Request::input('prerequisites', [])),
            json_encode(Request::input('learning_outcomes', []))
        ]);

        $courseId = $this->db->lastInsertId();

        Response::success(['id' => $courseId, 'slug' => $slug], 'AI course created successfully', 201);
    }

    /**
     * Update AI course
     * PUT /api/admin/ai-courses/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT id FROM ai_courses WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Course not found', 404);
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['title', 'description', 'short_description', 'thumbnail',
                   'category_id', 'level', 'language', 'estimated_hours'];

        foreach ($fields as $field) {
            if (Request::has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = Request::input($field);
            }
        }

        // Boolean fields
        foreach (['is_free', 'is_featured', 'is_published'] as $field) {
            if (Request::has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = Request::input($field) ? 1 : 0;
            }
        }

        // JSON fields
        foreach (['tags', 'prerequisites', 'learning_outcomes'] as $field) {
            if (Request::has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = json_encode(Request::input($field));
            }
        }

        if (Request::has('title')) {
            $updates[] = "slug = ?";
            $params[] = $this->generateSlug(Request::input('title'), $id);
        }

        if (empty($updates)) {
            Response::error('No fields to update');
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE ai_courses SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        // Update total lessons count
        $this->updateLessonCount($id);

        Response::success(null, 'AI course updated successfully');
    }

    /**
     * Delete AI course
     * DELETE /api/admin/ai-courses/{id}
     */
    public function delete(int $id): void {
        $stmt = $this->db->prepare("SELECT id FROM ai_courses WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Course not found', 404);
            return;
        }

        // Delete will cascade to modules and lessons
        $stmt = $this->db->prepare("DELETE FROM ai_courses WHERE id = ?");
        $stmt->execute([$id]);

        Response::success(null, 'AI course deleted successfully');
    }

    /**
     * Create module
     * POST /api/admin/ai-courses/{courseId}/modules
     */
    public function createModule(int $courseId): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255'
        ])) return;

        // Verify course exists
        $stmt = $this->db->prepare("SELECT id FROM ai_courses WHERE id = ?");
        $stmt->execute([$courseId]);
        if (!$stmt->fetch()) {
            Response::error('Course not found', 404);
            return;
        }

        // Get next sort order
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ai_modules WHERE course_id = ?");
        $stmt->execute([$courseId]);
        $sortOrder = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO ai_modules (course_id, title, description, sort_order, is_published, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $courseId,
            Request::input('title'),
            Request::input('description'),
            Request::input('sort_order', $sortOrder),
            Request::input('is_published', true) ? 1 : 0
        ]);

        $moduleId = $this->db->lastInsertId();

        Response::success(['id' => $moduleId], 'Module created successfully', 201);
    }

    /**
     * Update module
     * PUT /api/admin/ai-modules/{id}
     */
    public function updateModule(int $id): void {
        $stmt = $this->db->prepare("SELECT id FROM ai_modules WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Module not found', 404);
            return;
        }

        $updates = [];
        $params = [];

        foreach (['title', 'description', 'sort_order'] as $field) {
            if (Request::has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = Request::input($field);
            }
        }

        if (Request::has('is_published')) {
            $updates[] = "is_published = ?";
            $params[] = Request::input('is_published') ? 1 : 0;
        }

        if (empty($updates)) {
            Response::error('No fields to update');
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE ai_modules SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Module updated successfully');
    }

    /**
     * Delete module
     * DELETE /api/admin/ai-modules/{id}
     */
    public function deleteModule(int $id): void {
        $stmt = $this->db->prepare("SELECT course_id FROM ai_modules WHERE id = ?");
        $stmt->execute([$id]);
        $module = $stmt->fetch();

        if (!$module) {
            Response::error('Module not found', 404);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM ai_modules WHERE id = ?");
        $stmt->execute([$id]);

        // Update lesson count
        $this->updateLessonCount($module['course_id']);

        Response::success(null, 'Module deleted successfully');
    }

    /**
     * Create lesson
     * POST /api/admin/ai-modules/{moduleId}/lessons
     */
    public function createLesson(int $moduleId): void {
        if (!$this->validate([
            'title' => 'required|min:3|max:255'
        ])) return;

        // Verify module exists and get course ID
        $stmt = $this->db->prepare("SELECT course_id FROM ai_modules WHERE id = ?");
        $stmt->execute([$moduleId]);
        $module = $stmt->fetch();

        if (!$module) {
            Response::error('Module not found', 404);
            return;
        }

        // Get next sort order
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ai_lessons WHERE module_id = ?");
        $stmt->execute([$moduleId]);
        $sortOrder = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO ai_lessons (
                module_id, title, description, objectives, key_concepts,
                teaching_notes, estimated_minutes, difficulty, sort_order, is_published, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $moduleId,
            Request::input('title'),
            Request::input('description'),
            json_encode(Request::input('objectives', [])),
            json_encode(Request::input('key_concepts', [])),
            Request::input('teaching_notes'),
            Request::input('estimated_minutes', 15),
            Request::input('difficulty', 1),
            Request::input('sort_order', $sortOrder),
            Request::input('is_published', true) ? 1 : 0
        ]);

        $lessonId = $this->db->lastInsertId();

        // Update lesson count
        $this->updateLessonCount($module['course_id']);

        Response::success(['id' => $lessonId], 'Lesson created successfully', 201);
    }

    /**
     * Update lesson
     * PUT /api/admin/ai-lessons/{id}
     */
    public function updateLesson(int $id): void {
        $stmt = $this->db->prepare("SELECT id FROM ai_lessons WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Lesson not found', 404);
            return;
        }

        $updates = [];
        $params = [];

        foreach (['title', 'description', 'teaching_notes', 'estimated_minutes', 'difficulty', 'sort_order'] as $field) {
            if (Request::has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = Request::input($field);
            }
        }

        foreach (['objectives', 'key_concepts'] as $field) {
            if (Request::has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = json_encode(Request::input($field));
            }
        }

        if (Request::has('is_published')) {
            $updates[] = "is_published = ?";
            $params[] = Request::input('is_published') ? 1 : 0;
        }

        if (empty($updates)) {
            Response::error('No fields to update');
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE ai_lessons SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Lesson updated successfully');
    }

    /**
     * Delete lesson
     * DELETE /api/admin/ai-lessons/{id}
     */
    public function deleteLesson(int $id): void {
        $stmt = $this->db->prepare("
            SELECT l.id, m.course_id
            FROM ai_lessons l
            JOIN ai_modules m ON l.module_id = m.id
            WHERE l.id = ?
        ");
        $stmt->execute([$id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            Response::error('Lesson not found', 404);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM ai_lessons WHERE id = ?");
        $stmt->execute([$id]);

        // Update lesson count
        $this->updateLessonCount($lesson['course_id']);

        Response::success(null, 'Lesson deleted successfully');
    }

    /**
     * Generate unique slug
     */
    private function generateSlug(string $title, ?int $excludeId = null): string {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_courses WHERE slug = ?" .
            ($excludeId ? " AND id != ?" : "")
        );
        $params = [$slug];
        if ($excludeId) $params[] = $excludeId;
        $stmt->execute($params);

        if ($stmt->fetchColumn() > 0) {
            $slug .= '-' . time();
        }

        return $slug;
    }

    /**
     * Update total lessons count for a course
     */
    private function updateLessonCount(int $courseId): void {
        $stmt = $this->db->prepare("
            UPDATE ai_courses SET
                total_lessons = (
                    SELECT COUNT(*) FROM ai_lessons l
                    JOIN ai_modules m ON l.module_id = m.id
                    WHERE m.course_id = ?
                ),
                estimated_hours = (
                    SELECT COALESCE(SUM(l.estimated_minutes), 0) / 60 FROM ai_lessons l
                    JOIN ai_modules m ON l.module_id = m.id
                    WHERE m.course_id = ?
                )
            WHERE id = ?
        ");
        $stmt->execute([$courseId, $courseId, $courseId]);
    }
}
