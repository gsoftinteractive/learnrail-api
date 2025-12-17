<?php
/**
 * Base Controller
 */

abstract class Controller {

    protected ?PDO $db = null;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();

        if (!$this->db) {
            Response::serverError('Database connection failed');
        }
    }

    /**
     * Get authenticated user ID
     */
    protected function userId(): ?int {
        return $GLOBALS['auth_user_id'] ?? null;
    }

    /**
     * Get authenticated user
     */
    protected function user(): ?array {
        $userId = $this->userId();
        if (!$userId) return null;

        $stmt = $this->db->prepare("
            SELECT id, email, first_name, last_name, phone, avatar, role,
                   total_points, created_at, updated_at
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get user's active subscription
     */
    protected function subscription(): ?array {
        return $GLOBALS['auth_subscription'] ?? null;
    }

    /**
     * Validate request and return errors if any
     */
    protected function validate(array $rules): bool {
        $errors = Request::validate($rules);

        if (!empty($errors)) {
            Response::validationError($errors);
            return false;
        }

        return true;
    }

    /**
     * Get pagination offset
     */
    protected function paginate(): array {
        return Request::pagination();
    }

    /**
     * Hash password
     */
    protected function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify password
     */
    protected function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    /**
     * Generate unique slug
     */
    protected function generateSlug(string $title, string $table, string $column = 'slug'): string {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        $originalSlug = $slug;
        $count = 1;

        while (true) {
            $stmt = $this->db->prepare("SELECT id FROM $table WHERE $column = ? LIMIT 1");
            $stmt->execute([$slug]);

            if (!$stmt->fetch()) {
                break;
            }

            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * Upload file
     */
    protected function uploadFile(array $file, string $folder = 'uploads'): ?string {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $uploadDir = UPLOAD_PATH . $folder . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return $folder . '/' . $filename;
        }

        return null;
    }

    /**
     * Delete file
     */
    protected function deleteFile(string $path): bool {
        $fullPath = UPLOAD_PATH . $path;
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    /**
     * Award points to user
     */
    protected function awardPoints(int $userId, int $points, string $reason): void {
        // Update total points
        $stmt = $this->db->prepare("
            UPDATE users SET total_points = total_points + ? WHERE id = ?
        ");
        $stmt->execute([$points, $userId]);

        // Log transaction
        $stmt = $this->db->prepare("
            INSERT INTO points_transactions (user_id, points, reason, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $points, $reason]);
    }
}
