<?php
/**
 * Career Controller
 * Handles career assessment and recommendations
 */

class CareerController extends Controller {

    /**
     * Submit career assessment
     * POST /api/career/assess
     */
    public function assess(): void {
        if (!$this->validate([
            'interests' => 'required',
            'experience_level' => 'required|in:beginner,intermediate,advanced',
            'goals' => 'required'
        ])) return;

        $userId = $this->userId();
        $interests = Request::input('interests');
        $experienceLevel = Request::input('experience_level');
        $goals = Request::input('goals');

        // Generate recommendations based on assessment
        $recommendations = $this->generateRecommendations($interests, $experienceLevel, $goals);

        // Save assessment
        $stmt = $this->db->prepare("
            INSERT INTO career_assessments (user_id, interests, experience_level, goals, recommendations, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            json_encode($interests),
            $experienceLevel,
            json_encode($goals),
            json_encode($recommendations)
        ]);

        $assessmentId = $this->db->lastInsertId();

        Response::created([
            'assessment_id' => $assessmentId,
            'recommendations' => $recommendations
        ], 'Assessment completed');
    }

    /**
     * Get career recommendations
     * GET /api/career/recommendations
     */
    public function recommendations(): void {
        $userId = $this->userId();

        // Get latest assessment
        $stmt = $this->db->prepare("
            SELECT id, interests, experience_level, goals, recommendations, created_at
            FROM career_assessments
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $assessment = $stmt->fetch();

        if (!$assessment) {
            Response::success([
                'has_assessment' => false,
                'message' => 'Complete a career assessment to get personalized recommendations'
            ]);
            return;
        }

        // Parse JSON fields
        $assessment['interests'] = json_decode($assessment['interests'], true);
        $assessment['goals'] = json_decode($assessment['goals'], true);
        $assessment['recommendations'] = json_decode($assessment['recommendations'], true);

        // Get recommended courses
        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.slug, c.short_description, c.thumbnail,
                   c.level, c.duration_hours, c.rating, c.total_enrollments,
                   cat.name as category_name
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.id
            WHERE c.is_published = 1
            ORDER BY c.rating DESC, c.total_enrollments DESC
            LIMIT 6
        ");
        $stmt->execute();
        $recommendedCourses = $stmt->fetchAll();

        Response::success([
            'has_assessment' => true,
            'assessment' => $assessment,
            'recommended_courses' => $recommendedCourses
        ]);
    }

    /**
     * Get assessment history
     * GET /api/career/history
     */
    public function history(): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            SELECT id, interests, experience_level, goals, created_at
            FROM career_assessments
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $assessments = $stmt->fetchAll();

        foreach ($assessments as &$assessment) {
            $assessment['interests'] = json_decode($assessment['interests'], true);
            $assessment['goals'] = json_decode($assessment['goals'], true);
        }

        Response::success($assessments);
    }

    /**
     * Generate recommendations based on assessment
     */
    private function generateRecommendations(array $interests, string $experienceLevel, array $goals): array {
        $recommendations = [
            'career_paths' => [],
            'skills_to_learn' => [],
            'suggested_courses' => [],
            'next_steps' => []
        ];

        // Map interests to career paths
        $careerMappings = [
            'technology' => [
                'paths' => ['Software Developer', 'Data Analyst', 'Web Developer', 'IT Support'],
                'skills' => ['Programming', 'Database Management', 'Cloud Computing', 'Cybersecurity']
            ],
            'business' => [
                'paths' => ['Business Analyst', 'Project Manager', 'Entrepreneur', 'Marketing Manager'],
                'skills' => ['Business Strategy', 'Financial Analysis', 'Leadership', 'Marketing']
            ],
            'creative' => [
                'paths' => ['Graphic Designer', 'UX Designer', 'Content Creator', 'Video Producer'],
                'skills' => ['Design Thinking', 'Visual Design', 'Video Editing', 'Copywriting']
            ],
            'personal_development' => [
                'paths' => ['Life Coach', 'HR Manager', 'Training Specialist', 'Consultant'],
                'skills' => ['Communication', 'Leadership', 'Emotional Intelligence', 'Public Speaking']
            ],
            'health' => [
                'paths' => ['Health Coach', 'Fitness Trainer', 'Nutritionist', 'Wellness Consultant'],
                'skills' => ['Nutrition', 'Fitness Training', 'Mental Health', 'Health Education']
            ]
        ];

        foreach ($interests as $interest) {
            $interest = strtolower(str_replace([' ', '-'], '_', $interest));
            if (isset($careerMappings[$interest])) {
                $recommendations['career_paths'] = array_merge(
                    $recommendations['career_paths'],
                    $careerMappings[$interest]['paths']
                );
                $recommendations['skills_to_learn'] = array_merge(
                    $recommendations['skills_to_learn'],
                    $careerMappings[$interest]['skills']
                );
            }
        }

        // Remove duplicates
        $recommendations['career_paths'] = array_unique($recommendations['career_paths']);
        $recommendations['skills_to_learn'] = array_unique($recommendations['skills_to_learn']);

        // Adjust based on experience level
        $levelAdvice = [
            'beginner' => [
                'Focus on foundational courses',
                'Build a portfolio with practice projects',
                'Join online communities for networking'
            ],
            'intermediate' => [
                'Pursue specialized certifications',
                'Take on challenging projects',
                'Consider mentoring beginners'
            ],
            'advanced' => [
                'Develop leadership skills',
                'Explore consulting opportunities',
                'Share knowledge through teaching'
            ]
        ];

        $recommendations['next_steps'] = $levelAdvice[$experienceLevel] ?? $levelAdvice['beginner'];

        // Get relevant courses from database
        if (!empty($interests)) {
            $placeholders = implode(',', array_fill(0, count($interests), '?'));
            $stmt = $this->db->prepare("
                SELECT c.id, c.title, c.slug
                FROM courses c
                LEFT JOIN categories cat ON c.category_id = cat.id
                WHERE c.is_published = 1 AND cat.slug IN ($placeholders)
                ORDER BY c.rating DESC
                LIMIT 4
            ");
            $stmt->execute($interests);
            $recommendations['suggested_courses'] = $stmt->fetchAll();
        }

        return $recommendations;
    }
}
