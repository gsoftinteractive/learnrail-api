<?php
/**
 * Certificate Controller
 */

class CertificateController extends Controller {

    /**
     * List user's certificates
     * GET /api/certificates
     */
    public function index(): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            SELECT cert.id, cert.certificate_number, cert.issued_at, cert.pdf_url,
                   c.id as course_id, c.title as course_title, c.slug as course_slug,
                   c.thumbnail as course_thumbnail,
                   i.name as instructor_name
            FROM certificates cert
            JOIN courses c ON cert.course_id = c.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            WHERE cert.user_id = ?
            ORDER BY cert.issued_at DESC
        ");
        $stmt->execute([$userId]);
        $certificates = $stmt->fetchAll();

        Response::success($certificates);
    }

    /**
     * Get single certificate
     * GET /api/certificates/{id}
     */
    public function show(int $id): void {
        $userId = $this->userId();

        $stmt = $this->db->prepare("
            SELECT cert.*,
                   c.title as course_title, c.description as course_description,
                   c.duration_hours, c.total_lessons,
                   i.name as instructor_name, i.title as instructor_title,
                   u.first_name, u.last_name
            FROM certificates cert
            JOIN courses c ON cert.course_id = c.id
            LEFT JOIN instructors i ON c.instructor_id = i.id
            JOIN users u ON cert.user_id = u.id
            WHERE cert.id = ? AND cert.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        $certificate = $stmt->fetch();

        if (!$certificate) {
            Response::notFound('Certificate not found');
            return;
        }

        Response::success($certificate);
    }

    /**
     * Verify certificate (public)
     * GET /api/certificates/verify/{number}
     */
    public function verify(string $number): void {
        $stmt = $this->db->prepare("
            SELECT cert.certificate_number, cert.issued_at,
                   c.title as course_title,
                   u.first_name, u.last_name
            FROM certificates cert
            JOIN courses c ON cert.course_id = c.id
            JOIN users u ON cert.user_id = u.id
            WHERE cert.certificate_number = ?
        ");
        $stmt->execute([$number]);
        $certificate = $stmt->fetch();

        if (!$certificate) {
            Response::notFound('Certificate not found or invalid');
            return;
        }

        Response::success([
            'valid' => true,
            'certificate' => $certificate
        ]);
    }
}
