<?php
/**
 * Admin Report Controller
 */

class AdminReportController extends Controller {

    /**
     * User reports
     * GET /api/admin/reports/users
     */
    public function users(): void {
        $period = Request::query('period', '30'); // days

        // Total users
        $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
        $totalUsers = (int) $stmt->fetchColumn();

        // New users in period
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users
            WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$period]);
        $newUsers = (int) $stmt->fetchColumn();

        // Active users (logged in during period)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users
            WHERE role = 'user' AND last_login >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$period]);
        $activeUsers = (int) $stmt->fetchColumn();

        // Users with subscriptions
        $stmt = $this->db->query("
            SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE status = 'active'
        ");
        $subscribedUsers = (int) $stmt->fetchColumn();

        // User growth by day
        $stmt = $this->db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM users WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stmt->execute([$period]);
        $dailySignups = $stmt->fetchAll();

        // Users by role
        $stmt = $this->db->query("
            SELECT role, COUNT(*) as count FROM users GROUP BY role
        ");
        $byRole = $stmt->fetchAll();

        Response::success([
            'total_users' => $totalUsers,
            'new_users' => $newUsers,
            'active_users' => $activeUsers,
            'subscribed_users' => $subscribedUsers,
            'conversion_rate' => $totalUsers > 0 ? round(($subscribedUsers / $totalUsers) * 100, 2) : 0,
            'daily_signups' => $dailySignups,
            'by_role' => $byRole
        ]);
    }

    /**
     * Revenue reports
     * GET /api/admin/reports/revenue
     */
    public function revenue(): void {
        $period = Request::query('period', '30');

        // Total revenue
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'");
        $totalRevenue = (float) $stmt->fetchColumn();

        // Revenue in period
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM payments
            WHERE status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$period]);
        $periodRevenue = (float) $stmt->fetchColumn();

        // Revenue by payment method
        $stmt = $this->db->query("
            SELECT payment_method, SUM(amount) as total, COUNT(*) as count
            FROM payments WHERE status = 'completed'
            GROUP BY payment_method
        ");
        $byMethod = $stmt->fetchAll();

        // Revenue by plan
        $stmt = $this->db->query("
            SELECT sp.name, SUM(p.amount) as total, COUNT(*) as count
            FROM payments p
            JOIN subscriptions s ON p.subscription_id = s.id
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE p.status = 'completed'
            GROUP BY sp.id
            ORDER BY total DESC
        ");
        $byPlan = $stmt->fetchAll();

        // Daily revenue
        $stmt = $this->db->prepare("
            SELECT DATE(paid_at) as date, SUM(amount) as total, COUNT(*) as count
            FROM payments
            WHERE status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(paid_at)
            ORDER BY date
        ");
        $stmt->execute([$period]);
        $dailyRevenue = $stmt->fetchAll();

        // Monthly revenue (last 12 months)
        $stmt = $this->db->query("
            SELECT DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total
            FROM payments
            WHERE status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month
            ORDER BY month
        ");
        $monthlyRevenue = $stmt->fetchAll();

        Response::success([
            'total_revenue' => $totalRevenue,
            'period_revenue' => $periodRevenue,
            'by_method' => $byMethod,
            'by_plan' => $byPlan,
            'daily_revenue' => $dailyRevenue,
            'monthly_revenue' => $monthlyRevenue
        ]);
    }

    /**
     * Course reports
     * GET /api/admin/reports/courses
     */
    public function courses(): void {
        // Total courses
        $stmt = $this->db->query("SELECT COUNT(*) FROM courses");
        $totalCourses = (int) $stmt->fetchColumn();

        // Published courses
        $stmt = $this->db->query("SELECT COUNT(*) FROM courses WHERE is_published = 1");
        $publishedCourses = (int) $stmt->fetchColumn();

        // Total enrollments
        $stmt = $this->db->query("SELECT COUNT(*) FROM enrollments");
        $totalEnrollments = (int) $stmt->fetchColumn();

        // Completed enrollments
        $stmt = $this->db->query("SELECT COUNT(*) FROM enrollments WHERE status = 'completed'");
        $completedEnrollments = (int) $stmt->fetchColumn();

        // Top courses by enrollment
        $stmt = $this->db->query("
            SELECT c.id, c.title, c.total_enrollments, c.rating,
                   (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id AND status = 'completed') as completions
            FROM courses c
            WHERE c.is_published = 1
            ORDER BY c.total_enrollments DESC
            LIMIT 10
        ");
        $topCourses = $stmt->fetchAll();

        // Courses by category
        $stmt = $this->db->query("
            SELECT cat.name, COUNT(c.id) as count, SUM(c.total_enrollments) as enrollments
            FROM categories cat
            LEFT JOIN courses c ON c.category_id = cat.id AND c.is_published = 1
            GROUP BY cat.id
            ORDER BY enrollments DESC
        ");
        $byCategory = $stmt->fetchAll();

        // Average completion rate
        $completionRate = $totalEnrollments > 0
            ? round(($completedEnrollments / $totalEnrollments) * 100, 2)
            : 0;

        // Certificates issued
        $stmt = $this->db->query("SELECT COUNT(*) FROM certificates");
        $certificatesIssued = (int) $stmt->fetchColumn();

        Response::success([
            'total_courses' => $totalCourses,
            'published_courses' => $publishedCourses,
            'total_enrollments' => $totalEnrollments,
            'completed_enrollments' => $completedEnrollments,
            'completion_rate' => $completionRate,
            'certificates_issued' => $certificatesIssued,
            'top_courses' => $topCourses,
            'by_category' => $byCategory
        ]);
    }
}
