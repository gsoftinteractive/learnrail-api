<?php
/**
 * Admin Instructor Controller
 */

class AdminInstructorController extends Controller {

    /**
     * List all instructors
     * GET /api/admin/instructors
     */
    public function index(): void {
        $stmt = $this->db->query("
            SELECT i.*,
                   (SELECT COUNT(*) FROM courses WHERE instructor_id = i.id) as course_count,
                   u.email as user_email
            FROM instructors i
            LEFT JOIN users u ON i.user_id = u.id
            ORDER BY i.name
        ");
        $instructors = $stmt->fetchAll();

        foreach ($instructors as &$instructor) {
            $instructor['expertise'] = json_decode($instructor['expertise'], true) ?? [];
            $instructor['social_links'] = json_decode($instructor['social_links'], true) ?? [];
        }

        Response::success($instructors);
    }

    /**
     * Create instructor
     * POST /api/admin/instructors
     */
    public function create(): void {
        if (!$this->validate([
            'name' => 'required|min:2|max:100'
        ])) return;

        $stmt = $this->db->prepare("
            INSERT INTO instructors (user_id, name, bio, avatar, title, expertise, social_links, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            Request::input('user_id'),
            Request::input('name'),
            Request::input('bio'),
            Request::input('avatar'),
            Request::input('title'),
            json_encode(Request::input('expertise', [])),
            json_encode(Request::input('social_links', [])),
            Request::input('is_active', true) ? 1 : 0
        ]);

        Response::created(['id' => $this->db->lastInsertId()], 'Instructor created');
    }

    /**
     * Update instructor
     * PUT /api/admin/instructors/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM instructors WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Instructor not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['user_id', 'name', 'bio', 'avatar', 'title', 'is_active'];
        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        // Handle JSON fields
        $expertise = Request::input('expertise');
        if ($expertise !== null) {
            $updates[] = "expertise = ?";
            $params[] = json_encode($expertise);
        }

        $socialLinks = Request::input('social_links');
        if ($socialLinks !== null) {
            $updates[] = "social_links = ?";
            $params[] = json_encode($socialLinks);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE instructors SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Instructor updated');
    }

    /**
     * Delete instructor
     * DELETE /api/admin/instructors/{id}
     */
    public function delete(int $id): void {
        // Check if instructor has courses
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM courses WHERE instructor_id = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            Response::error('Cannot delete instructor with courses', 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM instructors WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Instructor not found');
            return;
        }

        Response::success(null, 'Instructor deleted');
    }
}
