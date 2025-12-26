<?php
/**
 * Admin Dashboard Controller
 */

class AdminController extends Controller {

    /**
     * Get dashboard stats
     * GET /api/admin/dashboard
     */
    public function dashboard(): void {
        // Total users
        $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
        $totalUsers = (int) $stmt->fetchColumn();

        // New users this month
        $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $newUsersThisMonth = (int) $stmt->fetchColumn();

        // Active subscriptions
        $stmt = $this->db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
        $activeSubscriptions = (int) $stmt->fetchColumn();

        // Total revenue
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'");
        $totalRevenue = (float) $stmt->fetchColumn();

        // Revenue this month
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $revenueThisMonth = (float) $stmt->fetchColumn();

        // Total courses
        $stmt = $this->db->query("SELECT COUNT(*) FROM courses");
        $totalCourses = (int) $stmt->fetchColumn();

        // Published courses
        $stmt = $this->db->query("SELECT COUNT(*) FROM courses WHERE is_published = 1");
        $publishedCourses = (int) $stmt->fetchColumn();

        // Total enrollments
        $stmt = $this->db->query("SELECT COUNT(*) FROM enrollments");
        $totalEnrollments = (int) $stmt->fetchColumn();

        // Completed courses
        $stmt = $this->db->query("SELECT COUNT(*) FROM enrollments WHERE status = 'completed'");
        $completedCourses = (int) $stmt->fetchColumn();

        // Pending payments (bank transfer)
        $stmt = $this->db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND payment_method = 'bank_transfer'");
        $pendingPayments = (int) $stmt->fetchColumn();

        // Recent activity
        $stmt = $this->db->query("
            SELECT 'enrollment' as type, u.first_name, u.last_name, c.title as course_title, e.enrolled_at as created_at
            FROM enrollments e
            JOIN users u ON e.user_id = u.id
            JOIN courses c ON e.course_id = c.id
            ORDER BY e.enrolled_at DESC
            LIMIT 5
        ");
        $recentEnrollments = $stmt->fetchAll();

        $stmt = $this->db->query("
            SELECT u.first_name, u.last_name, u.email, u.created_at
            FROM users u
            WHERE u.role = 'user'
            ORDER BY u.created_at DESC
            LIMIT 5
        ");
        $recentUsers = $stmt->fetchAll();

        // Chart data - users per month (last 6 months)
        $stmt = $this->db->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
            FROM users WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month ORDER BY month
        ");
        $userGrowth = $stmt->fetchAll();

        // Chart data - revenue per month (last 6 months)
        $stmt = $this->db->query("
            SELECT DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total
            FROM payments WHERE status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month ORDER BY month
        ");
        $revenueGrowth = $stmt->fetchAll();

        Response::success([
            'stats' => [
                'total_users' => $totalUsers,
                'new_users_this_month' => $newUsersThisMonth,
                'active_subscriptions' => $activeSubscriptions,
                'total_revenue' => $totalRevenue,
                'revenue_this_month' => $revenueThisMonth,
                'total_courses' => $totalCourses,
                'published_courses' => $publishedCourses,
                'total_enrollments' => $totalEnrollments,
                'completed_courses' => $completedCourses,
                'pending_payments' => $pendingPayments
            ],
            'recent_enrollments' => $recentEnrollments,
            'recent_users' => $recentUsers,
            'charts' => [
                'user_growth' => $userGrowth,
                'revenue_growth' => $revenueGrowth
            ]
        ]);
    }
}
