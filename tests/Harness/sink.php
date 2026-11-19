<?php

// A local webhook receiver for the harness checks. Run inside the web container:
//   php -S 127.0.0.1:18093 sink.php
// Appends each request (path, headers, body) to /tmp/switchboard-sink.jsonl.
// /fail answers 500; /redirect answers 302 to /landed.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
file_put_contents('/tmp/switchboard-sink.jsonl', json_encode([
    'path' => $path, 'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'headers' => $headers, 'body' => file_get_contents('php://input'),
])."\n", FILE_APPEND | LOCK_EX);
if ($path === '/fail') {
    http_response_code(500);
} elseif ($path === '/redirect') {
    header('Location: /landed', true, 302);
} else {
    echo 'ok';
}
