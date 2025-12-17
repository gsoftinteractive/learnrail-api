<?php
/**
 * Simple Router
 */

class Router {

    private array $routes = [];
    private array $middlewares = [];
    private string $prefix = '';
    private array $groupMiddlewares = [];

    /**
     * Add GET route
     */
    public function get(string $path, callable|array $handler, array $middlewares = []): self {
        return $this->addRoute('GET', $path, $handler, $middlewares);
    }

    /**
     * Add POST route
     */
    public function post(string $path, callable|array $handler, array $middlewares = []): self {
        return $this->addRoute('POST', $path, $handler, $middlewares);
    }

    /**
     * Add PUT route
     */
    public function put(string $path, callable|array $handler, array $middlewares = []): self {
        return $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    /**
     * Add PATCH route
     */
    public function patch(string $path, callable|array $handler, array $middlewares = []): self {
        return $this->addRoute('PATCH', $path, $handler, $middlewares);
    }

    /**
     * Add DELETE route
     */
    public function delete(string $path, callable|array $handler, array $middlewares = []): self {
        return $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    /**
     * Group routes with prefix and middlewares
     */
    public function group(array $options, callable $callback): void {
        $previousPrefix = $this->prefix;
        $previousMiddlewares = $this->groupMiddlewares;

        if (isset($options['prefix'])) {
            $this->prefix .= $options['prefix'];
        }

        if (isset($options['middleware'])) {
            $middlewares = is_array($options['middleware']) ? $options['middleware'] : [$options['middleware']];
            $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middlewares);
        }

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->groupMiddlewares = $previousMiddlewares;
    }

    /**
     * Add route
     */
    private function addRoute(string $method, string $path, callable|array $handler, array $middlewares): self {
        $fullPath = $this->prefix . $path;
        $allMiddlewares = array_merge($this->groupMiddlewares, $middlewares);

        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middlewares' => $allMiddlewares,
            'pattern' => $this->pathToPattern($fullPath)
        ];

        return $this;
    }

    /**
     * Convert path to regex pattern
     */
    private function pathToPattern(string $path): string {
        // Convert {param} to named capture groups
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    /**
     * Dispatch request
     */
    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Auto-detect and remove base path for subfolder installations
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $basePath = dirname($scriptName);

        // Remove base path from URI
        if ($basePath !== '/' && $basePath !== '\\' && strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }

        // Ensure URI starts with /
        if (empty($uri) || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        // Handle OPTIONS for CORS
        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run middlewares
                foreach ($route['middlewares'] as $middleware) {
                    $result = $this->runMiddleware($middleware);
                    if ($result === false) {
                        return;
                    }
                }

                // Call handler
                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // No route matched
        Response::notFound('Endpoint not found');
    }

    /**
     * Run middleware
     */
    private function runMiddleware(string $middleware): bool {
        switch ($middleware) {
            case 'auth':
                return $this->authMiddleware();
            case 'admin':
                return $this->adminMiddleware();
            case 'subscribed':
                return $this->subscribedMiddleware();
            default:
                return true;
        }
    }

    /**
     * Auth middleware
     */
    private function authMiddleware(): bool {
        $token = Request::bearerToken();

        if (!$token) {
            Response::unauthorized('No token provided');
            return false;
        }

        $payload = JWT::validate($token);

        if (!$payload || ($payload['type'] ?? '') === 'refresh') {
            Response::unauthorized('Invalid or expired token');
            return false;
        }

        // Store user ID in global for controllers
        $GLOBALS['auth_user_id'] = $payload['user_id'];

        return true;
    }

    /**
     * Admin middleware
     */
    private function adminMiddleware(): bool {
        if (!$this->authMiddleware()) {
            return false;
        }

        // Check if user is admin
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$GLOBALS['auth_user_id']]);
        $user = $stmt->fetch();

        if (!$user || $user['role'] !== 'admin') {
            Response::forbidden('Admin access required');
            return false;
        }

        return true;
    }

    /**
     * Subscribed middleware
     */
    private function subscribedMiddleware(): bool {
        if (!$this->authMiddleware()) {
            return false;
        }

        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT s.* FROM subscriptions s
            WHERE s.user_id = ?
            AND s.status = 'active'
            AND s.end_date > NOW()
            LIMIT 1
        ");
        $stmt->execute([$GLOBALS['auth_user_id']]);
        $subscription = $stmt->fetch();

        if (!$subscription) {
            Response::forbidden('Active subscription required');
            return false;
        }

        $GLOBALS['auth_subscription'] = $subscription;

        return true;
    }

    /**
     * Call route handler
     */
    private function callHandler(callable|array $handler, array $params): void {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $controller->$method(...array_values($params));
        } else {
            $handler(...array_values($params));
        }
    }
}
