<?php
/**
 * Quiz Controller
 */

class QuizController extends Controller {

    /**
     * Get quiz with questions
     * GET /api/quizzes/{id}
     */
    public function show(int $id): void {
        $userId = $this->userId();

        // Get quiz with lesson and course info
        $stmt = $this->db->prepare("
            SELECT q.*, l.title as lesson_title, m.course_id
            FROM quizzes q
            JOIN lessons l ON q.lesson_id = l.id
            JOIN modules m ON l.module_id = m.id
            WHERE q.id = ?
        ");
        $stmt->execute([$id]);
        $quiz = $stmt->fetch();

        if (!$quiz) {
            Response::notFound('Quiz not found');
            return;
        }

        // Verify enrollment
        $stmt = $this->db->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?");
        $stmt->execute([$userId, $quiz['course_id']]);
        if (!$stmt->fetch()) {
            Response::forbidden('Enrollment required');
            return;
        }

        // Check max attempts
        if ($quiz['max_attempts'] > 0) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?");
            $stmt->execute([$userId, $id]);
            $attempts = (int) $stmt->fetchColumn();

            if ($attempts >= $quiz['max_attempts']) {
                Response::error('Maximum attempts reached', 400);
                return;
            }
            $quiz['remaining_attempts'] = $quiz['max_attempts'] - $attempts;
        }

        // Get questions (without correct answers)
        $stmt = $this->db->prepare("
            SELECT id, question, type, options, points, sort_order
            FROM quiz_questions
            WHERE quiz_id = ?
            ORDER BY sort_order
        ");
        $stmt->execute([$id]);
        $questions = $stmt->fetchAll();

        foreach ($questions as &$question) {
            $question['options'] = json_decode($question['options'], true);
        }

        $quiz['questions'] = $questions;

        // Get previous attempts
        $stmt = $this->db->prepare("
            SELECT id, score, passed, time_taken, created_at
            FROM quiz_attempts
            WHERE user_id = ? AND quiz_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId, $id]);
        $quiz['previous_attempts'] = $stmt->fetchAll();

        Response::success($quiz);
    }

    /**
     * Submit quiz answers
     * POST /api/quizzes/{id}/submit
     */
    public function submit(int $id): void {
        $userId = $this->userId();
        $answers = Request::input('answers', []);
        $timeTaken = Request::input('time_taken');

        // Get quiz
        $stmt = $this->db->prepare("
            SELECT q.*, m.course_id, l.id as lesson_id
            FROM quizzes q
            JOIN lessons l ON q.lesson_id = l.id
            JOIN modules m ON l.module_id = m.id
            WHERE q.id = ?
        ");
        $stmt->execute([$id]);
        $quiz = $stmt->fetch();

        if (!$quiz) {
            Response::notFound('Quiz not found');
            return;
        }

        // Check max attempts
        if ($quiz['max_attempts'] > 0) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?");
            $stmt->execute([$userId, $id]);
            if ((int) $stmt->fetchColumn() >= $quiz['max_attempts']) {
                Response::error('Maximum attempts reached', 400);
                return;
            }
        }

        // Get questions with correct answers
        $stmt = $this->db->prepare("SELECT id, correct_answer, points FROM quiz_questions WHERE quiz_id = ?");
        $stmt->execute([$id]);
        $questions = $stmt->fetchAll();

        // Calculate score
        $totalPoints = 0;
        $earnedPoints = 0;
        $results = [];

        foreach ($questions as $question) {
            $totalPoints += $question['points'];
            $correctAnswer = json_decode($question['correct_answer'], true);
            $userAnswer = $answers[$question['id']] ?? null;

            $isCorrect = $this->checkAnswer($correctAnswer, $userAnswer);
            if ($isCorrect) {
                $earnedPoints += $question['points'];
            }

            $results[$question['id']] = [
                'correct' => $isCorrect,
                'correct_answer' => $correctAnswer,
                'user_answer' => $userAnswer
            ];
        }

        $score = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100) : 0;
        $passed = $score >= $quiz['passing_score'];

        // Save attempt
        $stmt = $this->db->prepare("
            INSERT INTO quiz_attempts (user_id, quiz_id, score, passed, answers, time_taken, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $id, $score, $passed, json_encode($answers), $timeTaken]);

        // Award points if passed
        if ($passed) {
            $this->awardPoints($userId, 25, 'Passed a quiz');

            // Mark lesson as complete
            $stmt = $this->db->prepare("
                INSERT INTO lesson_progress (user_id, lesson_id, status, completed_at, created_at)
                VALUES (?, ?, 'completed', NOW(), NOW())
                ON DUPLICATE KEY UPDATE status = 'completed', completed_at = IFNULL(completed_at, NOW())
            ");
            $stmt->execute([$userId, $quiz['lesson_id']]);
        }

        Response::success([
            'score' => $score,
            'passed' => $passed,
            'earned_points' => $earnedPoints,
            'total_points' => $totalPoints,
            'passing_score' => $quiz['passing_score'],
            'results' => $results
        ]);
    }

    /**
     * Check if answer is correct
     */
    private function checkAnswer($correct, $answer): bool {
        if (is_array($correct)) {
            if (!is_array($answer)) return false;
            sort($correct);
            sort($answer);
            return $correct === $answer;
        }
        return $correct == $answer;
    }
}
