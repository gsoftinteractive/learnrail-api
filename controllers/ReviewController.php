<?php
/**
 * Review Controller
 */

class ReviewController extends Controller {

    /**
     * Get course reviews
     * GET /api/courses/{id}/reviews
     */
    public function index(int $courseId): void {
        $pagination = $this->paginate();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM reviews WHERE course_id = ? AND is_approved = 1");
        $stmt->execute([$courseId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT r.id, r.rating, r.comment, r.created_at,
                   u.id as user_id, u.first_name, u.last_name, u.avatar
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            WHERE r.course_id = ? AND r.is_approved = 1
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$courseId, $pagination['per_page'], $pagination['offset']]);
        $reviews = $stmt->fetchAll();

        // Get rating distribution
        $stmt = $this->db->prepare("
            SELECT rating, COUNT(*) as count
            FROM reviews
            WHERE course_id = ? AND is_approved = 1
            GROUP BY rating
            ORDER BY rating DESC
        ");
        $stmt->execute([$courseId]);
        $distribution = [];
        foreach ($stmt->fetchAll() as $row) {
            $distribution[$row['rating']] = $row['count'];
        }

        // Get average rating
        $stmt = $this->db->prepare("
            SELECT AVG(rating) as avg_rating
            FROM reviews
            WHERE course_id = ? AND is_approved = 1
        ");
        $stmt->execute([$courseId]);
        $avgRating = round((float) $stmt->fetchColumn(), 1);

        Response::json([
            'success' => true,
            'data' => $reviews,
            'stats' => [
                'average_rating' => $avgRating,
                'total_reviews' => $total,
                'distribution' => $distribution
            ],
            'meta' => [
                'current_page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
                'last_page' => ceil($total / $pagination['per_page']),
                'has_more' => $pagination['page'] < ceil($total / $pagination['per_page'])
            ]
        ]);
    }

    /**
     * Create course review
     * POST /api/courses/{id}/reviews
     */
    public function create(int $courseId): void {
        if (!$this->validate([
            'rating' => 'required|integer',
            'comment' => 'max:1000'
        ])) return;

        $userId = $this->userId();
        $rating = (int) Request::input('rating');
        $comment = Request::input('comment');

        // Validate rating range
        if ($rating < 1 || $rating > 5) {
            Response::validationError(['rating' => 'Rating must be between 1 and 5']);
            return;
        }

        // Check enrollment and completion
        $stmt = $this->db->prepare("
            SELECT progress_percent FROM enrollments
            WHERE user_id = ? AND course_id = ?
        ");
        $stmt->execute([$userId, $courseId]);
        $enrollment = $stmt->fetch();

        if (!$enrollment) {
            Response::forbidden('You must be enrolled in this course to review');
            return;
        }

        if ($enrollment['progress_percent'] < 50) {
            Response::error('Complete at least 50% of the course before reviewing', 400);
            return;
        }

        // Check existing review
        $stmt = $this->db->prepare("SELECT id FROM reviews WHERE user_id = ? AND course_id = ?");
        $stmt->execute([$userId, $courseId]);
        if ($stmt->fetch()) {
            Response::error('You have already reviewed this course', 400);
            return;
        }

        // Create review
        $stmt = $this->db->prepare("
            INSERT INTO reviews (user_id, course_id, rating, comment, is_approved, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$userId, $courseId, $rating, $comment]);
        $reviewId = $this->db->lastInsertId();

        // Update course rating
        $this->updateCourseRating($courseId);

        // Award points
        $this->awardPoints($userId, 5, 'Submitted a course review');

        Response::created([
            'review_id' => $reviewId,
            'rating' => $rating
        ], 'Review submitted successfully');
    }

    /**
     * Update review
     * PUT /api/reviews/{id}
     */
    public function update(int $id): void {
        $userId = $this->userId();

        // Get existing review
        $stmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $review = $stmt->fetch();

        if (!$review) {
            Response::notFound('Review not found');
            return;
        }

        $rating = Request::input('rating', $review['rating']);
        $comment = Request::input('comment', $review['comment']);

        // Validate rating
        if ($rating < 1 || $rating > 5) {
            Response::validationError(['rating' => 'Rating must be between 1 and 5']);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE reviews SET rating = ?, comment = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$rating, $comment, $id]);

        // Update course rating
        $this->updateCourseRating($review['course_id']);

        Response::success(['rating' => $rating], 'Review updated successfully');
    }

    /**
     * Delete review
     * DELETE /api/reviews/{id}
     */
    public function delete(int $id): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("SELECT course_id FROM reviews WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $review = $stmt->fetch();

        if (!$review) {
            Response::notFound('Review not found');
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->execute([$id]);

        // Update course rating
        $this->updateCourseRating($review['course_id']);

        Response::success(null, 'Review deleted');
    }

    /**
     * Update course rating after review changes
     */
    private function updateCourseRating(int $courseId): void {
        $stmt = $this->db->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total
            FROM reviews
            WHERE course_id = ? AND is_approved = 1
        ");
        $stmt->execute([$courseId]);
        $stats = $stmt->fetch();

        $stmt = $this->db->prepare("
            UPDATE courses SET rating = ?, total_reviews = ?
            WHERE id = ?
        ");
        $stmt->execute([round($stats['avg_rating'], 1), $stats['total'], $courseId]);
    }
}
