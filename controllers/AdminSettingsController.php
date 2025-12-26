<?php
/**
 * Admin Settings Controller
 */

class AdminSettingsController extends Controller {

    /**
     * Get all settings
     * GET /api/admin/settings
     */
    public function index(): void {
        $stmt = $this->db->query("SELECT * FROM settings ORDER BY `key`");
        $settings = [];

        foreach ($stmt->fetchAll() as $row) {
            $value = $row['value'];

            // Parse value based on type
            switch ($row['type']) {
                case 'integer':
                    $value = (int) $value;
                    break;
                case 'boolean':
                    $value = $value === '1' || $value === 'true';
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }

            $settings[$row['key']] = [
                'value' => $value,
                'type' => $row['type'],
                'description' => $row['description']
            ];
        }

        Response::success($settings);
    }

    /**
     * Update settings
     * PUT /api/admin/settings
     */
    public function update(): void {
        $settings = Request::body();

        foreach ($settings as $key => $value) {
            // Get setting type
            $stmt = $this->db->prepare("SELECT type FROM settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $setting = $stmt->fetch();

            if (!$setting) {
                // Create new setting
                $type = 'string';
                if (is_bool($value)) $type = 'boolean';
                elseif (is_int($value)) $type = 'integer';
                elseif (is_array($value)) $type = 'json';

                $stmt = $this->db->prepare("
                    INSERT INTO settings (`key`, value, type) VALUES (?, ?, ?)
                ");
                $stmt->execute([$key, is_array($value) ? json_encode($value) : (string) $value, $type]);
            } else {
                // Update existing
                $storedValue = $value;
                if ($setting['type'] === 'json') {
                    $storedValue = json_encode($value);
                } elseif ($setting['type'] === 'boolean') {
                    $storedValue = $value ? '1' : '0';
                }

                $stmt = $this->db->prepare("UPDATE settings SET value = ? WHERE `key` = ?");
                $stmt->execute([(string) $storedValue, $key]);
            }
        }

        Response::success(null, 'Settings updated');
    }

    /**
     * Get payment methods
     * GET /api/admin/payment-methods
     */
    public function paymentMethods(): void {
        $stmt = $this->db->query("SELECT * FROM payment_methods ORDER BY sort_order");
        $methods = $stmt->fetchAll();

        foreach ($methods as &$method) {
            $method['config'] = json_decode($method['config'], true);
        }

        Response::success($methods);
    }

    /**
     * Update payment method
     * PUT /api/admin/payment-methods/{id}
     */
    public function updatePaymentMethod(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM payment_methods WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Payment method not found');
            return;
        }

        $updates = [];
        $params = [];

        $fields = ['name', 'is_active', 'sort_order'];
        foreach ($fields as $field) {
            $value = Request::input($field);
            if ($value !== null) {
                $updates[] = "$field = ?";
                $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
            }
        }

        $config = Request::input('config');
        if ($config !== null) {
            $updates[] = "config = ?";
            $params[] = json_encode($config);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
            return;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE payment_methods SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        Response::success(null, 'Payment method updated');
    }
}
