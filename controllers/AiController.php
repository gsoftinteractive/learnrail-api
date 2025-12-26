<?php
/**
 * AI Controller
 * Handles AI tutor chat functionality with Claude/OpenAI support
 * Supports curriculum-based teaching for AI courses
 */

class AiController extends Controller {

    /**
     * Send message to AI tutor
     * POST /api/ai/chat
     */
    public function chat(): void {
        if (!$this->validate([
            'message' => 'required|min:1|max:2000'
        ])) return;

        $userId = $this->userId();
        $message = Request::input('message');
        $sessionId = Request::input('session_id') ?: $this->generateSessionId();
        $context = Request::input('context', []);
        $courseId = Request::input('course_id');
        $lessonId = Request::input('lesson_id');

        // Get course/lesson context if provided
        if ($courseId) {
            $context = array_merge($context, $this->getCourseContext($courseId, $lessonId));
        }

        // Save user message
        $stmt = $this->db->prepare("
            INSERT INTO ai_chat_history (user_id, session_id, role, content, context, created_at)
            VALUES (?, ?, 'user', ?, ?, NOW())
        ");
        $stmt->execute([$userId, $sessionId, $message, json_encode($context)]);

        // Get chat history for context
        $stmt = $this->db->prepare("
            SELECT role, content
            FROM ai_chat_history
            WHERE user_id = ? AND session_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$userId, $sessionId]);
        $history = array_reverse($stmt->fetchAll());

        // Generate AI response
        $aiResponse = $this->generateAiResponse($message, $history, $context);

        // Save AI response
        $stmt = $this->db->prepare("
            INSERT INTO ai_chat_history (user_id, session_id, role, content, context, created_at)
            VALUES (?, ?, 'assistant', ?, ?, NOW())
        ");
        $stmt->execute([$userId, $sessionId, $aiResponse, json_encode($context)]);

        Response::success([
            'session_id' => $sessionId,
            'message' => $aiResponse,
            'context' => $context
        ]);
    }

    /**
     * Start AI lesson - proactive teaching
     * POST /api/ai/start-lesson
     */
    public function startLesson(): void {
        if (!$this->validate([
            'course_id' => 'required|integer',
            'lesson_id' => 'required|integer'
        ])) return;

        $userId = $this->userId();
        $courseId = Request::input('course_id');
        $lessonId = Request::input('lesson_id');

        // Get AI course and lesson details
        $lesson = $this->getAiLesson($courseId, $lessonId);
        if (!$lesson) {
            Response::error('Lesson not found', 404);
            return;
        }

        $sessionId = $this->generateSessionId();
        $context = [
            'course_id' => $courseId,
            'course_title' => $lesson['course_title'],
            'lesson_id' => $lessonId,
            'lesson_title' => $lesson['lesson_title'],
            'lesson_objectives' => $lesson['objectives'],
            'teaching_mode' => true
        ];

        // Generate the lesson introduction
        $prompt = $this->buildLessonPrompt($lesson);
        $aiResponse = $this->generateAiResponse($prompt, [], $context, true);

        // Save the AI's opening message
        $stmt = $this->db->prepare("
            INSERT INTO ai_chat_history (user_id, session_id, role, content, context, created_at)
            VALUES (?, ?, 'assistant', ?, ?, NOW())
        ");
        $stmt->execute([$userId, $sessionId, $aiResponse, json_encode($context)]);

        // Track lesson start
        $this->trackLessonProgress($userId, $courseId, $lessonId, 'started');

        Response::success([
            'session_id' => $sessionId,
            'message' => $aiResponse,
            'lesson' => $lesson,
            'context' => $context
        ]);
    }

    /**
     * Get AI course curriculum
     * GET /api/ai/courses/{id}/curriculum
     */
    public function curriculum(int $courseId): void {
        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.description, c.thumbnail, c.level,
                   c.total_lessons, c.estimated_hours
            FROM ai_courses c
            WHERE c.id = ? AND c.is_published = 1
        ");
        $stmt->execute([$courseId]);
        $course = $stmt->fetch();

        if (!$course) {
            Response::error('Course not found', 404);
            return;
        }

        // Get modules and lessons
        $stmt = $this->db->prepare("
            SELECT m.id as module_id, m.title as module_title, m.description as module_description,
                   m.sort_order as module_order,
                   l.id as lesson_id, l.title as lesson_title, l.description as lesson_description,
                   l.objectives, l.estimated_minutes, l.sort_order as lesson_order
            FROM ai_modules m
            LEFT JOIN ai_lessons l ON l.module_id = m.id AND l.is_published = 1
            WHERE m.course_id = ? AND m.is_published = 1
            ORDER BY m.sort_order, l.sort_order
        ");
        $stmt->execute([$courseId]);
        $rows = $stmt->fetchAll();

        // Structure into modules with lessons
        $modules = [];
        foreach ($rows as $row) {
            $moduleId = $row['module_id'];
            if (!isset($modules[$moduleId])) {
                $modules[$moduleId] = [
                    'id' => $moduleId,
                    'title' => $row['module_title'],
                    'description' => $row['module_description'],
                    'sort_order' => $row['module_order'],
                    'lessons' => []
                ];
            }
            if ($row['lesson_id']) {
                $modules[$moduleId]['lessons'][] = [
                    'id' => $row['lesson_id'],
                    'title' => $row['lesson_title'],
                    'description' => $row['lesson_description'],
                    'objectives' => json_decode($row['objectives'], true),
                    'estimated_minutes' => $row['estimated_minutes'],
                    'sort_order' => $row['lesson_order']
                ];
            }
        }

        // Get user progress if authenticated
        $userId = $this->userId(false);
        $progress = [];
        if ($userId) {
            $stmt = $this->db->prepare("
                SELECT lesson_id, status, completed_at
                FROM ai_lesson_progress
                WHERE user_id = ? AND course_id = ?
            ");
            $stmt->execute([$userId, $courseId]);
            $progressRows = $stmt->fetchAll();
            foreach ($progressRows as $p) {
                $progress[$p['lesson_id']] = [
                    'status' => $p['status'],
                    'completed_at' => $p['completed_at']
                ];
            }
        }

        $course['modules'] = array_values($modules);
        $course['user_progress'] = $progress;

        Response::success($course);
    }

    /**
     * Get chat history
     * GET /api/ai/history
     */
    public function history(): void {
        $userId = $this->userId();
        $sessionId = Request::query('session_id');
        $pagination = $this->paginate();

        if ($sessionId) {
            // Get specific session
            $stmt = $this->db->prepare("
                SELECT id, role, content, context, created_at
                FROM ai_chat_history
                WHERE user_id = ? AND session_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$userId, $sessionId]);
            $messages = $stmt->fetchAll();

            foreach ($messages as &$msg) {
                $msg['context'] = json_decode($msg['context'], true);
            }

            Response::success([
                'session_id' => $sessionId,
                'messages' => $messages
            ]);
        } else {
            // Get all sessions
            $stmt = $this->db->prepare("
                SELECT session_id,
                       MIN(created_at) as started_at,
                       MAX(created_at) as last_message_at,
                       COUNT(*) as message_count,
                       (SELECT content FROM ai_chat_history h2
                        WHERE h2.session_id = ai_chat_history.session_id
                        AND h2.user_id = ? AND h2.role = 'user'
                        ORDER BY h2.created_at LIMIT 1) as first_message
                FROM ai_chat_history
                WHERE user_id = ?
                GROUP BY session_id
                ORDER BY last_message_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $userId, $pagination['per_page'], $pagination['offset']]);
            $sessions = $stmt->fetchAll();

            // Get total
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT session_id) FROM ai_chat_history WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $total = (int) $stmt->fetchColumn();

            Response::paginated($sessions, $total, $pagination['page'], $pagination['per_page']);
        }
    }

    /**
     * Complete AI lesson
     * POST /api/ai/lessons/{id}/complete
     */
    public function completeLesson(int $lessonId): void {
        $userId = $this->userId();

        // Get lesson details
        $stmt = $this->db->prepare("
            SELECT l.*, m.course_id
            FROM ai_lessons l
            JOIN ai_modules m ON l.module_id = m.id
            WHERE l.id = ?
        ");
        $stmt->execute([$lessonId]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            Response::error('Lesson not found', 404);
            return;
        }

        // Mark lesson as completed
        $this->trackLessonProgress($userId, $lesson['course_id'], $lessonId, 'completed');

        // Award points
        $this->awardPoints($userId, POINTS_LESSON_COMPLETE, 'ai_lesson_complete', $lessonId);

        // Check if module is complete
        $moduleComplete = $this->checkModuleCompletion($userId, $lesson['module_id']);

        // Check if course is complete
        $courseComplete = false;
        if ($moduleComplete) {
            $courseComplete = $this->checkCourseCompletion($userId, $lesson['course_id']);
            if ($courseComplete) {
                $this->awardPoints($userId, POINTS_COURSE_COMPLETE, 'ai_course_complete', $lesson['course_id']);
            }
        }

        // Get next lesson
        $nextLesson = $this->getNextLesson($lesson['course_id'], $lesson['module_id'], $lesson['sort_order']);

        Response::success([
            'completed' => true,
            'points_earned' => POINTS_LESSON_COMPLETE,
            'module_complete' => $moduleComplete,
            'course_complete' => $courseComplete,
            'next_lesson' => $nextLesson
        ]);
    }

    /**
     * Delete chat session
     * DELETE /api/ai/sessions/{sessionId}
     */
    public function deleteSession(string $sessionId): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            DELETE FROM ai_chat_history WHERE user_id = ? AND session_id = ?
        ");
        $stmt->execute([$userId, $sessionId]);

        Response::success(null, 'Chat session deleted');
    }

    /**
     * Generate AI response using configured provider
     */
    private function generateAiResponse(string $message, array $history, array $context, bool $isTeaching = false): string {
        $provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'claude';

        if ($provider === 'claude' && defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
            return $this->callClaudeAPI($message, $history, $context, $isTeaching);
        }

        if ($provider === 'openai' && defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
            return $this->callOpenAI($message, $history, $context);
        }

        // Fallback
        return $this->generateFallbackResponse($message, $context);
    }

    /**
     * Call Claude API (Anthropic)
     */
    private function callClaudeAPI(string $message, array $history, array $context, bool $isTeaching = false): string {
        $apiKey = ANTHROPIC_API_KEY;
        $model = defined('AI_MODEL') ? AI_MODEL : 'claude-3-haiku-20240307';

        $systemPrompt = $this->buildSystemPrompt($context, $isTeaching);

        $messages = [];
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Add the new user message if not already in history
        if (empty($history) || end($history)['content'] !== $message) {
            $messages[] = [
                'role' => 'user',
                'content' => $message
            ];
        }

        $data = [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => $messages
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Claude API error: " . $response);
            return $this->generateFallbackResponse($message, $context);
        }

        $result = json_decode($response, true);
        return $result['content'][0]['text'] ?? $this->generateFallbackResponse($message, $context);
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $message, array $history, array $context): string {
        $apiKey = OPENAI_API_KEY;

        $systemPrompt = $this->buildSystemPrompt($context, false);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 1024,
            'temperature' => 0.7
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("OpenAI API error: " . $response);
            return $this->generateFallbackResponse($message, $context);
        }

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? $this->generateFallbackResponse($message, $context);
    }

    /**
     * Build system prompt based on context
     */
    private function buildSystemPrompt(array $context, bool $isTeaching): string {
        $prompt = "You are an AI tutor for Learnrail, an online learning platform. ";

        if ($isTeaching && !empty($context['teaching_mode'])) {
            $prompt .= "You are currently teaching a lesson. Your role is to:
1. Explain concepts clearly and engagingly
2. Use examples and analogies to make concepts easy to understand
3. Ask questions to check understanding
4. Encourage the student and provide positive reinforcement
5. Break down complex topics into digestible parts
6. Provide code examples when relevant (properly formatted)

";
            if (!empty($context['lesson_title'])) {
                $prompt .= "Current lesson: {$context['lesson_title']}\n";
            }
            if (!empty($context['lesson_objectives'])) {
                $objectives = is_array($context['lesson_objectives'])
                    ? implode(', ', $context['lesson_objectives'])
                    : $context['lesson_objectives'];
                $prompt .= "Learning objectives: {$objectives}\n";
            }
        } else {
            $prompt .= "You help students understand course material, answer questions, and provide guidance. ";
            $prompt .= "Be helpful, encouraging, and explain concepts clearly. ";
        }

        if (!empty($context['course_title'])) {
            $prompt .= "The student is studying: {$context['course_title']}. ";
        }

        $prompt .= "\nKeep responses concise but thorough. Use markdown formatting for code and lists.";

        return $prompt;
    }

    /**
     * Build prompt for starting a lesson
     */
    private function buildLessonPrompt(array $lesson): string {
        $objectives = is_array($lesson['objectives'])
            ? implode(', ', $lesson['objectives'])
            : ($lesson['objectives'] ?? 'Learn the key concepts');

        return "START_LESSON_INSTRUCTION: Begin teaching the lesson titled '{$lesson['lesson_title']}'
from the course '{$lesson['course_title']}'.

Learning objectives for this lesson: {$objectives}

Please:
1. Introduce the lesson topic warmly
2. Explain why this topic is important
3. Begin teaching the first concept
4. End with a question to engage the student

Make it conversational and engaging.";
    }

    /**
     * Generate fallback response when AI is unavailable
     */
    private function generateFallbackResponse(string $message, array $context): string {
        $message = strtolower($message);

        if (strpos($message, 'hello') !== false || strpos($message, 'hi') !== false) {
            return "Hello! I'm your AI learning assistant. How can I help you today with your studies?";
        }

        if (strpos($message, 'help') !== false) {
            return "I'm here to help you learn! You can ask me questions about your courses, request explanations of concepts, or get study tips. What would you like to know?";
        }

        if (!empty($context['course_title'])) {
            return "That's a great question about {$context['course_title']}! Let me help you understand this better. Could you tell me more specifically what aspect you'd like me to explain?";
        }

        return "Thank you for your question! I'd be happy to help you learn. Could you provide a bit more detail about what you'd like to understand better?";
    }

    /**
     * Get course context
     */
    private function getCourseContext(int $courseId, ?int $lessonId): array {
        $context = [];

        // Check if it's an AI course first
        $stmt = $this->db->prepare("SELECT id, title FROM ai_courses WHERE id = ?");
        $stmt->execute([$courseId]);
        $aiCourse = $stmt->fetch();

        if ($aiCourse) {
            $context['course_id'] = $aiCourse['id'];
            $context['course_title'] = $aiCourse['title'];
            $context['is_ai_course'] = true;

            if ($lessonId) {
                $stmt = $this->db->prepare("SELECT id, title, objectives FROM ai_lessons WHERE id = ?");
                $stmt->execute([$lessonId]);
                $lesson = $stmt->fetch();
                if ($lesson) {
                    $context['lesson_id'] = $lesson['id'];
                    $context['lesson_title'] = $lesson['title'];
                    $context['lesson_objectives'] = json_decode($lesson['objectives'], true);
                }
            }
        } else {
            // Regular course
            $stmt = $this->db->prepare("SELECT id, title FROM courses WHERE id = ?");
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if ($course) {
                $context['course_id'] = $course['id'];
                $context['course_title'] = $course['title'];
                $context['is_ai_course'] = false;
            }
        }

        return $context;
    }

    /**
     * Get AI lesson details
     */
    private function getAiLesson(int $courseId, int $lessonId): ?array {
        $stmt = $this->db->prepare("
            SELECT l.id as lesson_id, l.title as lesson_title, l.description, l.objectives,
                   l.estimated_minutes, m.id as module_id, m.title as module_title,
                   c.id as course_id, c.title as course_title
            FROM ai_lessons l
            JOIN ai_modules m ON l.module_id = m.id
            JOIN ai_courses c ON m.course_id = c.id
            WHERE c.id = ? AND l.id = ? AND l.is_published = 1
        ");
        $stmt->execute([$courseId, $lessonId]);
        $lesson = $stmt->fetch();

        if ($lesson) {
            $lesson['objectives'] = json_decode($lesson['objectives'], true);
        }

        return $lesson ?: null;
    }

    /**
     * Track lesson progress
     */
    private function trackLessonProgress(int $userId, int $courseId, int $lessonId, string $status): void {
        $stmt = $this->db->prepare("
            INSERT INTO ai_lesson_progress (user_id, course_id, lesson_id, status, started_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = ?, updated_at = NOW()
            " . ($status === 'completed' ? ", completed_at = NOW()" : "")
        );
        $stmt->execute([$userId, $courseId, $lessonId, $status, $status]);
    }

    /**
     * Check if module is complete
     */
    private function checkModuleCompletion(int $userId, int $moduleId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM ai_lessons l
            LEFT JOIN ai_lesson_progress p ON l.id = p.lesson_id AND p.user_id = ?
            WHERE l.module_id = ? AND l.is_published = 1
        ");
        $stmt->execute([$userId, $moduleId]);
        $result = $stmt->fetch();

        return $result['total'] > 0 && $result['total'] == $result['completed'];
    }

    /**
     * Check if course is complete
     */
    private function checkCourseCompletion(int $userId, int $courseId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM ai_lessons l
            JOIN ai_modules m ON l.module_id = m.id
            LEFT JOIN ai_lesson_progress p ON l.id = p.lesson_id AND p.user_id = ?
            WHERE m.course_id = ? AND l.is_published = 1 AND m.is_published = 1
        ");
        $stmt->execute([$userId, $courseId]);
        $result = $stmt->fetch();

        return $result['total'] > 0 && $result['total'] == $result['completed'];
    }

    /**
     * Get next lesson in course
     */
    private function getNextLesson(int $courseId, int $currentModuleId, int $currentLessonOrder): ?array {
        // Try to get next lesson in same module
        $stmt = $this->db->prepare("
            SELECT l.id, l.title, m.id as module_id, m.title as module_title
            FROM ai_lessons l
            JOIN ai_modules m ON l.module_id = m.id
            WHERE m.id = ? AND l.sort_order > ? AND l.is_published = 1
            ORDER BY l.sort_order
            LIMIT 1
        ");
        $stmt->execute([$currentModuleId, $currentLessonOrder]);
        $nextLesson = $stmt->fetch();

        if ($nextLesson) {
            return $nextLesson;
        }

        // Try to get first lesson of next module
        $stmt = $this->db->prepare("
            SELECT l.id, l.title, m.id as module_id, m.title as module_title
            FROM ai_lessons l
            JOIN ai_modules m ON l.module_id = m.id
            WHERE m.course_id = ? AND m.sort_order > (
                SELECT sort_order FROM ai_modules WHERE id = ?
            ) AND l.is_published = 1 AND m.is_published = 1
            ORDER BY m.sort_order, l.sort_order
            LIMIT 1
        ");
        $stmt->execute([$courseId, $currentModuleId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Award points to user
     */
    private function awardPoints(int $userId, int $points, string $reason, int $referenceId): void {
        $stmt = $this->db->prepare("
            INSERT INTO points_transactions (user_id, points, reason, reference_type, reference_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $points, $reason, 'ai_lesson', $referenceId]);

        $stmt = $this->db->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
        $stmt->execute([$points, $userId]);
    }

    /**
     * Generate session ID
     */
    private function generateSessionId(): string {
        return 'session_' . bin2hex(random_bytes(16));
    }
}
