<?php

declare(strict_types=1);

final class Request
{
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        return is_array($payload) ? $payload : [];
    }

    public static function token(?array $payload = null): string
    {
        if (isset($_SERVER['HTTP_X_CHAT_TOKEN']) && is_string($_SERVER['HTTP_X_CHAT_TOKEN'])) {
            return trim($_SERVER['HTTP_X_CHAT_TOKEN']);
        }

        if (is_array($payload) && isset($payload['token']) && is_string($payload['token'])) {
            return trim($payload['token']);
        }

        return '';
    }

    public static function authToken(?array $payload = null): string
    {
        if (isset($_SERVER['HTTP_X_AUTH_TOKEN']) && is_string($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return trim($_SERVER['HTTP_X_AUTH_TOKEN']);
        }

        if (is_array($payload) && isset($payload['authToken']) && is_string($payload['authToken'])) {
            return trim($payload['authToken']);
        }

        return '';
    }

    public static function since(): int
    {
        return isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;
    }

    public static function pathSegment(int $index = 0): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH) ?: '';
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));

        return $parts[$index] ?? '';
    }
}
