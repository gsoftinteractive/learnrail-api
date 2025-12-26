<?php
/**
 * Accountability Controller
 * Handles accountability partner messaging
 */

class AccountabilityController extends Controller {

    /**
     * Get assigned accountability partner
     * GET /api/accountability/partner
     */
    public function partner(): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            SELECT aa.id as assignment_id, aa.assigned_at, aa.notes,
                   u.id as partner_id, u.first_name, u.last_name, u.email,
                   u.phone, u.avatar
            FROM accountability_assignments aa
            JOIN users u ON aa.partner_id = u.id
            WHERE aa.user_id = ? AND aa.status = 'active'
        ");
        $stmt->execute([$userId]);
        $partner = $stmt->fetch();

        if (!$partner) {
            Response::success([
                'has_partner' => false,
                'message' => 'No accountability partner assigned yet. Contact support to request one.'
            ]);
            return;
        }

        Response::success([
            'has_partner' => true,
            'partner' => $partner
        ]);
    }

    /**
     * Get conversations
     * GET /api/accountability/conversations
     */
    public function conversations(): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            SELECT c.id, c.last_message_at, c.created_at,
                   u.id as partner_id, u.first_name, u.last_name, u.avatar,
                   (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                   (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id != ? AND is_read = 0) as unread_count
            FROM conversations c
            JOIN users u ON (c.participant_1 = u.id OR c.participant_2 = u.id) AND u.id != ?
            WHERE c.participant_1 = ? OR c.participant_2 = ?
            ORDER BY c.last_message_at DESC
        ");
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $conversations = $stmt->fetchAll();

        Response::success($conversations);
    }

    /**
     * Get messages in a conversation
     * GET /api/accountability/messages/{conversationId}
     */
    public function messages(int $conversationId): void {
        $userId = $this->userId();
        $pagination = $this->paginate();

        // Verify user is participant
        $stmt = $this->db->prepare("
            SELECT id FROM conversations
            WHERE id = ? AND (participant_1 = ? OR participant_2 = ?)
        ");
        $stmt->execute([$conversationId, $userId, $userId]);
        if (!$stmt->fetch()) {
            Response::notFound('Conversation not found');
            return;
        }

        // Get total
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        $total = (int) $stmt->fetchColumn();

        // Get messages (newest first for pagination, then reverse)
        $stmt = $this->db->prepare("
            SELECT m.id, m.sender_id, m.content, m.type, m.attachment_url,
                   m.is_read, m.read_at, m.created_at,
                   u.first_name, u.last_name, u.avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$conversationId, $pagination['per_page'], $pagination['offset']]);
        $messages = array_reverse($stmt->fetchAll());

        // Mark messages as read
        $stmt = $this->db->prepare("
            UPDATE messages
            SET is_read = 1, read_at = NOW()
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);

        Response::paginated($messages, $total, $pagination['page'], $pagination['per_page']);
    }

    /**
     * Send a message
     * POST /api/accountability/messages
     */
    public function sendMessage(): void {
        if (!$this->validate([
            'content' => 'required|min:1|max:2000'
        ])) return;

        $userId = $this->userId();
        $content = Request::input('content');
        $type = Request::input('type', 'text');
        $conversationId = Request::input('conversation_id');

        // If no conversation ID, find or create conversation with assigned partner
        if (!$conversationId) {
            $stmt = $this->db->prepare("
                SELECT partner_id FROM accountability_assignments
                WHERE user_id = ? AND status = 'active'
            ");
            $stmt->execute([$userId]);
            $assignment = $stmt->fetch();

            if (!$assignment) {
                Response::error('No accountability partner assigned', 400);
                return;
            }

            $partnerId = $assignment['partner_id'];

            // Find existing conversation
            $stmt = $this->db->prepare("
                SELECT id FROM conversations
                WHERE (participant_1 = ? AND participant_2 = ?)
                   OR (participant_1 = ? AND participant_2 = ?)
            ");
            $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
            $conversation = $stmt->fetch();

            if ($conversation) {
                $conversationId = $conversation['id'];
            } else {
                // Create new conversation
                $stmt = $this->db->prepare("
                    INSERT INTO conversations (participant_1, participant_2, created_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$userId, $partnerId]);
                $conversationId = $this->db->lastInsertId();
            }
        } else {
            // Verify user is participant
            $stmt = $this->db->prepare("
                SELECT id FROM conversations
                WHERE id = ? AND (participant_1 = ? OR participant_2 = ?)
            ");
            $stmt->execute([$conversationId, $userId, $userId]);
            if (!$stmt->fetch()) {
                Response::notFound('Conversation not found');
                return;
            }
        }

        // Handle file upload
        $attachmentUrl = null;
        if ($type !== 'text') {
            $file = Request::file('attachment');
            if ($file) {
                $attachmentUrl = $this->uploadFile($file, 'messages');
            }
        }

        // Create message
        $stmt = $this->db->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, type, attachment_url, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$conversationId, $userId, $content, $type, $attachmentUrl]);
        $messageId = $this->db->lastInsertId();

        // Update conversation last message time
        $stmt = $this->db->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = ?");
        $stmt->execute([$conversationId]);

        // Get the created message
        $stmt = $this->db->prepare("
            SELECT m.*, u.first_name, u.last_name, u.avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        Response::created([
            'message' => $message,
            'conversation_id' => $conversationId
        ], 'Message sent');
    }

    /**
     * Mark messages as read
     * PUT /api/accountability/messages/{conversationId}/read
     */
    public function markRead(int $conversationId): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            UPDATE messages
            SET is_read = 1, read_at = NOW()
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);

        Response::success(['marked_count' => $stmt->rowCount()]);
    }
}
