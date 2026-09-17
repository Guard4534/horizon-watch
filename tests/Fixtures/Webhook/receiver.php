<?php

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scenario = explode('/', trim($path, '/'))[0] ?? '';

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

$handle = fopen((string) getenv('RECEIVER_LOG_FILE'), 'a');
flock($handle, LOCK_EX);
fwrite($handle, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
])."\n");
flock($handle, LOCK_UN);
fclose($handle);

$endless = function (int $status): void {
    http_response_code($status);
    header('Content-Type: text/plain');

    for ($sent = 0; $sent < 25 && ! connection_aborted(); $sent++) {
        echo str_repeat('x', 1024 * 1024);
        flush();
        usleep(50_000);
    }
};

switch ($scenario) {
    case 'accepted':
        http_response_code(202);
        echo 'response-body-secret-marker';
        break;

    case 'redirect':
        http_response_code(302);
        header('Location: /accepted');
        break;

    case 'not-found':
        http_response_code(404);
        echo 'response-body-secret-marker';
        break;

    case 'server-error':
        http_response_code(500);
        echo 'response-body-secret-marker';
        break;

    case 'slow':
        sleep(2);
        http_response_code(200);
        break;

    case 'endless':
        $endless(200);
        break;

    case 'endless-error':
        $endless(503);
        break;

    case 'announced-too-large':
        http_response_code(200);
        header('Content-Length: '.(10 * 1024 * 1024));
        echo 'x';
        flush();
        break;

    default:
        http_response_code(204);
}

return true;
