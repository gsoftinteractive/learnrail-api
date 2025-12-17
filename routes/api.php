<?php
/**
 * API Routes
 * Learnrail API
 */

$router = new Router();

// =============================================
// PUBLIC ROUTES (No authentication required)
// =============================================

// Health check
$router->get('/api/health', function() {
    Response::success([
        'status' => 'healthy',
        'version' => APP_VERSION,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
});

// Authentication
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);

// Public courses
$router->get('/api/courses', [CourseController::class, 'index']);
$router->get('/api/courses/{slug}', [CourseController::class, 'show']);
$router->get('/api/categories', [CategoryController::class, 'index']);

// Subscription plans
$router->get('/api/subscription-plans', [SubscriptionController::class, 'plans']);

// =============================================
// AUTHENTICATED ROUTES
// =============================================

$router->group(['middleware' => 'auth'], function($router) {

    // Auth
    $router->get('/api/auth/me', [AuthController::class, 'me']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);
    $router->post('/api/auth/change-password', [AuthController::class, 'changePassword']);

    // User profile
    $router->get('/api/profile', [UserController::class, 'profile']);
    $router->put('/api/profile', [UserController::class, 'updateProfile']);
    $router->post('/api/profile/avatar', [UserController::class, 'uploadAvatar']);

    // Enrollments
    $router->get('/api/enrollments', [EnrollmentController::class, 'index']);
    $router->post('/api/courses/{id}/enroll', [EnrollmentController::class, 'enroll']);
    $router->get('/api/courses/{id}/progress', [EnrollmentController::class, 'progress']);

    // Lessons
    $router->get('/api/lessons/{id}', [LessonController::class, 'show']);
    $router->post('/api/lessons/{id}/complete', [LessonController::class, 'complete']);
    $router->post('/api/lessons/{id}/progress', [LessonController::class, 'updateProgress']);

    // Quizzes
    $router->get('/api/quizzes/{id}', [QuizController::class, 'show']);
    $router->post('/api/quizzes/{id}/submit', [QuizController::class, 'submit']);

    // Certificates
    $router->get('/api/certificates', [CertificateController::class, 'index']);
    $router->get('/api/certificates/{id}', [CertificateController::class, 'show']);

    // Subscriptions
    $router->get('/api/subscriptions', [SubscriptionController::class, 'index']);
    $router->post('/api/subscriptions', [SubscriptionController::class, 'create']);
    $router->get('/api/subscriptions/{id}', [SubscriptionController::class, 'show']);

    // Payments
    $router->post('/api/payments/initialize', [PaymentController::class, 'initialize']);
    $router->post('/api/payments/verify', [PaymentController::class, 'verify']);
    $router->get('/api/payments', [PaymentController::class, 'index']);

    // Gamification
    $router->get('/api/leaderboard', [GamificationController::class, 'leaderboard']);
    $router->get('/api/badges', [GamificationController::class, 'badges']);
    $router->get('/api/achievements', [GamificationController::class, 'achievements']);
    $router->get('/api/points-history', [GamificationController::class, 'pointsHistory']);

    // Notifications
    $router->get('/api/notifications', [NotificationController::class, 'index']);
    $router->put('/api/notifications/{id}/read', [NotificationController::class, 'markRead']);
    $router->put('/api/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Reviews
    $router->post('/api/courses/{id}/reviews', [ReviewController::class, 'create']);
    $router->get('/api/courses/{id}/reviews', [ReviewController::class, 'index']);

    // AI Chat
    $router->post('/api/ai/chat', [AiController::class, 'chat']);
    $router->get('/api/ai/history', [AiController::class, 'history']);

    // Career Assessment
    $router->post('/api/career/assess', [CareerController::class, 'assess']);
    $router->get('/api/career/recommendations', [CareerController::class, 'recommendations']);

});

// =============================================
// SUBSCRIBER ROUTES (Active subscription required)
// =============================================

$router->group(['middleware' => ['auth', 'subscribed']], function($router) {

    // Goals
    $router->get('/api/goals', [GoalController::class, 'index']);
    $router->post('/api/goals', [GoalController::class, 'create']);
    $router->get('/api/goals/{id}', [GoalController::class, 'show']);
    $router->put('/api/goals/{id}', [GoalController::class, 'update']);
    $router->delete('/api/goals/{id}', [GoalController::class, 'delete']);
    $router->post('/api/goals/{id}/checkin', [GoalController::class, 'checkin']);

    // Milestones
    $router->post('/api/goals/{goalId}/milestones', [MilestoneController::class, 'create']);
    $router->put('/api/milestones/{id}', [MilestoneController::class, 'update']);
    $router->delete('/api/milestones/{id}', [MilestoneController::class, 'delete']);
    $router->post('/api/milestones/{id}/complete', [MilestoneController::class, 'complete']);

    // Accountability Partner
    $router->get('/api/accountability/partner', [AccountabilityController::class, 'partner']);
    $router->get('/api/accountability/conversations', [AccountabilityController::class, 'conversations']);
    $router->get('/api/accountability/messages/{conversationId}', [AccountabilityController::class, 'messages']);
    $router->post('/api/accountability/messages', [AccountabilityController::class, 'sendMessage']);

});

// =============================================
// ADMIN ROUTES
// =============================================

$router->group(['prefix' => '/api/admin', 'middleware' => 'admin'], function($router) {

    // Dashboard
    $router->get('/dashboard', [AdminController::class, 'dashboard']);

    // Users management
    $router->get('/users', [AdminUserController::class, 'index']);
    $router->get('/users/{id}', [AdminUserController::class, 'show']);
    $router->post('/users', [AdminUserController::class, 'create']);
    $router->put('/users/{id}', [AdminUserController::class, 'update']);
    $router->delete('/users/{id}', [AdminUserController::class, 'delete']);

    // Courses management
    $router->get('/courses', [AdminCourseController::class, 'index']);
    $router->post('/courses', [AdminCourseController::class, 'create']);
    $router->get('/courses/{id}', [AdminCourseController::class, 'show']);
    $router->put('/courses/{id}', [AdminCourseController::class, 'update']);
    $router->delete('/courses/{id}', [AdminCourseController::class, 'delete']);

    // Modules management
    $router->post('/courses/{courseId}/modules', [AdminModuleController::class, 'create']);
    $router->put('/modules/{id}', [AdminModuleController::class, 'update']);
    $router->delete('/modules/{id}', [AdminModuleController::class, 'delete']);

    // Lessons management
    $router->post('/modules/{moduleId}/lessons', [AdminLessonController::class, 'create']);
    $router->put('/lessons/{id}', [AdminLessonController::class, 'update']);
    $router->delete('/lessons/{id}', [AdminLessonController::class, 'delete']);

    // Categories management
    $router->get('/categories', [AdminCategoryController::class, 'index']);
    $router->post('/categories', [AdminCategoryController::class, 'create']);
    $router->put('/categories/{id}', [AdminCategoryController::class, 'update']);
    $router->delete('/categories/{id}', [AdminCategoryController::class, 'delete']);

    // Instructors management
    $router->get('/instructors', [AdminInstructorController::class, 'index']);
    $router->post('/instructors', [AdminInstructorController::class, 'create']);
    $router->put('/instructors/{id}', [AdminInstructorController::class, 'update']);
    $router->delete('/instructors/{id}', [AdminInstructorController::class, 'delete']);

    // Subscription plans management
    $router->get('/subscription-plans', [AdminSubscriptionController::class, 'plans']);
    $router->post('/subscription-plans', [AdminSubscriptionController::class, 'createPlan']);
    $router->put('/subscription-plans/{id}', [AdminSubscriptionController::class, 'updatePlan']);
    $router->delete('/subscription-plans/{id}', [AdminSubscriptionController::class, 'deletePlan']);

    // Subscriptions management
    $router->get('/subscriptions', [AdminSubscriptionController::class, 'index']);
    $router->put('/subscriptions/{id}', [AdminSubscriptionController::class, 'update']);

    // Payments management
    $router->get('/payments', [AdminPaymentController::class, 'index']);
    $router->put('/payments/{id}/approve', [AdminPaymentController::class, 'approve']);

    // Accountability assignments
    $router->get('/accountability/assignments', [AdminAccountabilityController::class, 'assignments']);
    $router->post('/accountability/assign', [AdminAccountabilityController::class, 'assign']);
    $router->delete('/accountability/assignments/{id}', [AdminAccountabilityController::class, 'unassign']);
    $router->get('/accountability/partners', [AdminAccountabilityController::class, 'partners']);

    // Badges & Achievements
    $router->get('/badges', [AdminGamificationController::class, 'badges']);
    $router->post('/badges', [AdminGamificationController::class, 'createBadge']);
    $router->put('/badges/{id}', [AdminGamificationController::class, 'updateBadge']);

    // Settings
    $router->get('/settings', [AdminSettingsController::class, 'index']);
    $router->put('/settings', [AdminSettingsController::class, 'update']);
    $router->get('/payment-methods', [AdminSettingsController::class, 'paymentMethods']);
    $router->put('/payment-methods/{id}', [AdminSettingsController::class, 'updatePaymentMethod']);

    // Reports
    $router->get('/reports/users', [AdminReportController::class, 'users']);
    $router->get('/reports/revenue', [AdminReportController::class, 'revenue']);
    $router->get('/reports/courses', [AdminReportController::class, 'courses']);

});

// Dispatch the request
$router->dispatch();
