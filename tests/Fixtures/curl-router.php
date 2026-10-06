<?php

/**
 * Router for `php -S` used by CurlTransportTest. It echoes the request back as JSON.
 *
 * Query parameters: status (the HTTP status to answer), sleep (seconds to wait first).
 */

declare(strict_types=1);

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

$sleep = $query['sleep'] ?? '0';
if (is_string($sleep) && ctype_digit($sleep)) {
    sleep((int) $sleep);
}
$status = $query['status'] ?? '200';

http_response_code(is_string($status) && ctype_digit($status) ? (int) $status : 200);
header('Content-Type: application/json');
header('X-Echo-Method: ' . $method);
header('X-Multi: first');
header('X-Multi: second', false);

echo json_encode([
    'method' => $method,
    'path' => parse_url($uri, PHP_URL_PATH),
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
], JSON_THROW_ON_ERROR);
