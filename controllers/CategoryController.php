<?php
/**
 * Category Controller
 */

class CategoryController extends Controller {

    /**
     * List all categories
     * GET /api/categories
     */
    public function index(): void {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.slug, c.description, c.icon, c.color,
                   (SELECT COUNT(*) FROM courses WHERE category_id = c.id AND is_published = 1) as course_count
            FROM categories c
            WHERE c.is_active = 1
            ORDER BY c.sort_order, c.name
        ");
        $stmt->execute();
        $categories = $stmt->fetchAll();

        Response::success($categories);
    }

    /**
     * Get single category with courses
     * GET /api/categories/{slug}
     */
    public function show(string $slug): void {
        $stmt = $this->db->prepare("
            SELECT id, name, slug, description, icon, color
            FROM categories
            WHERE slug = ? AND is_active = 1
        ");
        $stmt->execute([$slug]);
        $category = $stmt->fetch();

        if (!$category) {
            Response::notFound('Category not found');
            return;
        }

        // Get courses in category
        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.slug, c.short_description, c.thumbnail,
                   c.level, c.duration_hours, c.total_lessons, c.price, c.is_free,
                   c.rating, c.total_reviews, c.total_enrollments,
                   i.name as instructor_name, i.avatar as instructor_avatar
            FROM courses c
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE c.category_id = ? AND c.is_published = 1
            ORDER BY c.is_featured DESC, c.created_at DESC
        ");
        $stmt->execute([$category['id']]);
        $category['courses'] = $stmt->fetchAll();

        Response::success($category);
    }
}
