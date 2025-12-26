<?php
/**
 * Notification Controller
 */

class NotificationController extends Controller {

    /**
     * List user's notifications
     * GET /api/notifications
     */
    public function index(): void {
        $userId = $this->userId();
        $pagination = $this->paginate();
        $unreadOnly = Request::query('unread') === 'true';

        $where = 'user_id = ?';
        $params = [$userId];

        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT id, title, body, type, data, is_read, read_at, created_at
            FROM notifications
            WHERE $where
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        // Parse JSON data
        foreach ($notifications as &$notification) {
            $notification['data'] = json_decode($notification['data'], true);
            $notification['is_read'] = (bool) $notification['is_read'];
        }

        // Get unread count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        $unreadCount = (int) $stmt->fetchColumn();

        Response::json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $unreadCount,
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
     * Mark notification as read
     * PUT /api/notifications/{id}/read
     */
    public function markRead(int $id): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$id, $userId]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Notification not found');
            return;
        }

        Response::success(null, 'Notification marked as read');
    }

    /**
     * Mark all notifications as read
     * PUT /api/notifications/read-all
     */
    public function markAllRead(): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);

        Response::success(['marked_count' => $stmt->rowCount()], 'All notifications marked as read');
    }

    /**
     * Delete notification
     * DELETE /api/notifications/{id}
     */
    public function delete(int $id): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Notification not found');
            return;
        }

        Response::success(null, 'Notification deleted');
    }

    /**
     * Create notification (internal use)
     */
    public static function create(PDO $db, int $userId, string $title, string $body, string $type, array $data = []): void {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, body, type, data, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $title, $body, $type, json_encode($data)]);
    }
}
