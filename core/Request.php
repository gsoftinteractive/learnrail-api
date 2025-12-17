<?php
/**
 * Request Helper
 */

class Request {

    private static ?array $jsonBody = null;

    /**
     * Get request method
     */
    public static function method(): string {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Check if request method matches
     */
    public static function isMethod(string $method): bool {
        return strtoupper($_SERVER['REQUEST_METHOD']) === strtoupper($method);
    }

    /**
     * Get request URI
     */
    public static function uri(): string {
        $uri = $_SERVER['REQUEST_URI'];
        $uri = parse_url($uri, PHP_URL_PATH);
        return $uri;
    }

    /**
     * Get JSON body
     */
    public static function body(): array {
        if (self::$jsonBody === null) {
            $input = file_get_contents('php://input');
            self::$jsonBody = json_decode($input, true) ?? [];
        }
        return self::$jsonBody;
    }

    /**
     * Get specific field from body
     */
    public static function input(string $key, $default = null) {
        $body = self::body();
        return $body[$key] ?? $default;
    }

    /**
     * Get query parameter
     */
    public static function query(string $key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get all query parameters
     */
    public static function queryAll(): array {
        return $_GET;
    }

    /**
     * Get header value
     */
    public static function header(string $key, $default = null): ?string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Get Authorization Bearer token
     */
    public static function bearerToken(): ?string {
        $header = self::header('Authorization');

        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get pagination parameters
     */
    public static function pagination(): array {
        $page = max(1, (int) self::query('page', 1));
        $perPage = min(MAX_PAGE_SIZE, max(1, (int) self::query('per_page', DEFAULT_PAGE_SIZE)));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage
        ];
    }

    /**
     * Get uploaded file
     */
    public static function file(string $key): ?array {
        return $_FILES[$key] ?? null;
    }

    /**
     * Validate required fields
     */
    public static function validate(array $rules): array {
        $errors = [];
        $body = self::body();

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $body[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $ruleParts = explode(':', $rule);
                $ruleName = $ruleParts[0];
                $ruleParam = $ruleParts[1] ?? null;

                $error = self::validateRule($field, $value, $ruleName, $ruleParam);
                if ($error) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Validate single rule
     */
    private static function validateRule(string $field, $value, string $rule, $param): ?string {
        $fieldName = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if (empty($value) && $value !== '0' && $value !== 0) {
                    return "$fieldName is required";
                }
                break;

            case 'email':
                if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "$fieldName must be a valid email";
                }
                break;

            case 'min':
                if (strlen($value) < (int)$param) {
                    return "$fieldName must be at least $param characters";
                }
                break;

            case 'max':
                if (strlen($value) > (int)$param) {
                    return "$fieldName must not exceed $param characters";
                }
                break;

            case 'numeric':
                if ($value && !is_numeric($value)) {
                    return "$fieldName must be a number";
                }
                break;

            case 'integer':
                if ($value && !filter_var($value, FILTER_VALIDATE_INT)) {
                    return "$fieldName must be an integer";
                }
                break;

            case 'boolean':
                if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return "$fieldName must be a boolean";
                }
                break;

            case 'in':
                $options = explode(',', $param);
                if ($value && !in_array($value, $options)) {
                    return "$fieldName must be one of: $param";
                }
                break;

            case 'date':
                if ($value && !strtotime($value)) {
                    return "$fieldName must be a valid date";
                }
                break;

            case 'url':
                if ($value && !filter_var($value, FILTER_VALIDATE_URL)) {
                    return "$fieldName must be a valid URL";
                }
                break;
        }

        return null;
    }

    /**
     * Get client IP address
     */
    public static function ip(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get user agent
     */
    public static function userAgent(): string {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}
