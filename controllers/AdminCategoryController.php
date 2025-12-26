<?php
/**
 * Admin Category Controller
 */

class AdminCategoryController extends Controller {

    /**
     * List all categories
     * GET /api/admin/categories
     */
    public function index(): void {
        $stmt = $this->db->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM courses WHERE category_id = c.id) as course_count
            FROM categories c
            ORDER BY c.sort_order, c.name
        ");
        $categories = $stmt->fetchAll();

        Response::success($categories);
    }

    /**
     * Create category
     * POST /api/admin/categories
     */
    public function create(): void {
        if (!$this->validate([
            'name' => 'required|min:2|max:100'
        ])) return;

        $name = Request::input('name');
        $slug = $this->generateSlug($name, 'categories');

        // Get next sort order
        $stmt = $this->db->query("SELECT MAX(sort_order) FROM categories");
        $sortOrder = ((int) $stmt->fetchColumn()) + 1;

        $stmt = $this->db->prepare("
            INSERT INTO categories (name, slug, description, icon, color, parent_id, sort_order, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $name,
            $slug,
            Request::input('description'),
            Request::input('icon'),
            Request::input('color', '#6366F1'),
            Request::input('parent_id'),
            $sortOrder,
            Request::input('is_active', true) ? 1 : 0
        ]);

        Response::created([
            'id' => $this->db->lastInsertId(),
            'slug' => $slug
        ], 'Category created');
    }

    /**
     * Update category
     * PUT /api/admin/categories/{id}
     */
    public function update(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Category not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['name', 'description', 'icon', 'color', 'parent_id', 'sort_order', 'is_active'];
        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        // Update slug if name changed
        $newName = Request::input('name');
        if ($newName) {
            $updates[] = "slug = ?";
            $params[] = $this->generateSlug($newName, 'categories');
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE categories SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Category updated');
    }

    /**
     * Delete category
     * DELETE /api/admin/categories/{id}
     */
    public function delete(int $id): void {
        // Check if category has courses
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM courses WHERE category_id = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            Response::error('Cannot delete category with courses', 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Category not found');
            return;
        }

        Response::success(null, 'Category deleted');
    }
}
