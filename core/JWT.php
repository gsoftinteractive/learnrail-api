<?php
/**
 * JWT (JSON Web Token) Handler
 * Simple JWT implementation without external dependencies
 */

class JWT {

    /**
     * Generate a JWT token
     */
    public static function generate(array $payload, int $expiry = null): string {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $payload['iat'] = time();
        $payload['exp'] = time() + ($expiry ?? JWT_EXPIRY);

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Generate a refresh token
     */
    public static function generateRefreshToken(int $userId): string {
        return self::generate(['user_id' => $userId, 'type' => 'refresh'], JWT_REFRESH_EXPIRY);
    }

    /**
     * Validate and decode a JWT token
     */
    public static function validate(string $token): ?array {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);

        if (!$payload) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Get user ID from token
     */
    public static function getUserId(string $token): ?int {
        $payload = self::validate($token);
        return $payload['user_id'] ?? null;
    }

    /**
     * Check if token is a refresh token
     */
    public static function isRefreshToken(string $token): bool {
        $payload = self::validate($token);
        return ($payload['type'] ?? '') === 'refresh';
    }

    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
