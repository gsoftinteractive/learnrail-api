<?php
/**
 * Admin Accountability Controller
 */

class AdminAccountabilityController extends Controller {

    /**
     * List all assignments
     * GET /api/admin/accountability/assignments
     */
    public function assignments(): void {
        $pagination = $this->paginate();
        $status = Request::query('status');

        $where = ['1=1'];
        $params = [];

        if ($status) {
            $where[] = 'aa.status = ?';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM accountability_assignments aa WHERE $whereClause");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare("
            SELECT aa.*,
                   u.first_name as user_first_name, u.last_name as user_last_name, u.email as user_email,
                   p.first_name as partner_first_name, p.last_name as partner_last_name, p.email as partner_email,
                   (SELECT COUNT(*) FROM conversations c
                    WHERE (c.participant_1 = aa.user_id AND c.participant_2 = aa.partner_id)
                       OR (c.participant_1 = aa.partner_id AND c.participant_2 = aa.user_id)) as has_conversation
            FROM accountability_assignments aa
            JOIN users u ON aa.user_id = u.id
            JOIN users p ON aa.partner_id = p.id
            WHERE $whereClause
            ORDER BY aa.assigned_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $assignments = $stmt->fetchAll();

        Response::paginated($assignments, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Assign accountability partner
     * POST /api/admin/accountability/assign
     */
    public function assign(): void {
        if (!$this->validate([
            'user_id' => 'required|integer',
            'partner_id' => 'required|integer'
        ])) return;

        $userId = Request::input('user_id');
        $partnerId = Request::input('partner_id');

        // Verify both users exist
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id IN (?, ?)");
        $stmt->execute([$userId, $partnerId]);
        if ($stmt->rowCount() < 2) {
            Response::error('Invalid user or partner ID', 400);
            return;
        }

        // Check if assignment already exists
        $stmt = $this->db->prepare("
            SELECT id FROM accountability_assignments
            WHERE user_id = ? AND partner_id = ?
        ");
        $stmt->execute([$userId, $partnerId]);
        if ($stmt->fetch()) {
            Response::error('Assignment already exists', 400);
            return;
        }

        // Deactivate any existing assignment for this user
        $stmt = $this->db->prepare("
            UPDATE accountability_assignments SET status = 'inactive'
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);

        // Create new assignment
        $stmt = $this->db->prepare("
            INSERT INTO accountability_assignments (user_id, partner_id, status, notes, assigned_at)
            VALUES (?, ?, 'active', ?, NOW())
        ");
        $stmt->execute([$userId, $partnerId, Request::input('notes')]);

        // Notify user
        NotificationController::create(
            $this->db,
            $userId,
            'Accountability Partner Assigned',
            'You have been assigned an accountability partner. Start your journey together!',
            'accountability',
            ['assignment_id' => $this->db->lastInsertId()]
        );

        Response::created(['id' => $this->db->lastInsertId()], 'Partner assigned');
    }

    /**
     * Remove assignment
     * DELETE /api/admin/accountability/assignments/{id}
     */
    public function unassign(int $id): void {
        $stmt = $this->db->prepare("UPDATE accountability_assignments SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Assignment not found');
            return;
        }

        Response::success(null, 'Assignment removed');
    }

    /**
     * List all accountability partners (staff with partner role)
     * GET /api/admin/accountability/partners
     */
    public function partners(): void {
        $stmt = $this->db->query("
            SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.avatar, u.created_at,
                   (SELECT COUNT(*) FROM accountability_assignments
                    WHERE partner_id = u.id AND status = 'active') as active_assignments
            FROM users u
            WHERE u.role = 'partner' AND u.status = 'active'
            ORDER BY u.first_name, u.last_name
        ");
        $partners = $stmt->fetchAll();

        Response::success($partners);
    }
}
