<?php

declare(strict_types=1);

/*
 * The real ThinQHttpTransport (the one class that touches the network) against a local
 * PHP server: status line, body on 4xx (ignore_errors), 204, connection refused. Runs
 * without bootstrap.php, which would replace the class with the fake.
 */

require __DIR__ . '/../LG ThinQ Bridge/libs/ThinQHttpTransport.php';

$GLOBALS['checks'] = 0;
function check(bool $condition, string $label): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "ok   $label\n";
}

$router = sys_get_temp_dir() . '/lgtq_transport_router_' . getmypid() . '.php';
file_put_contents($router, <<<'PHP'
<?php
$status = (int)($_GET['status'] ?? 200);
http_response_code($status);
header('Content-Type: application/json');
if ($status !== 204) {
    echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        'body' => file_get_contents('php://input'), 'status' => $status]);
}
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}

echo "== Echter Transport\n";
$t = new ThinQHttpTransport();
$base = 'http://127.0.0.1:' . $port . '/';
$r = $t->send('POST', $base . 'x?status=200', ['Authorization: Bearer abc', 'Content-Type: application/json'], '{"a":1}', 5);
$body = json_decode((string)$r['body'], true);
check($r['status'] === 200 && $r['statusLine'] === 'HTTP/1.1 200 OK', 'Statuszeile wird gelesen');
check($body['method'] === 'POST' && $body['auth'] === 'Bearer abc' && $body['body'] === '{"a":1}', 'Methode, Kopfzeilen und Rumpf kommen an');
$r = $t->send('GET', $base . 'x?status=404', [], null, 5);
check($r['status'] === 404 && json_decode((string)$r['body'], true)['status'] === 404, 'bei 404 kommt der Rumpf trotzdem (ignore_errors)');
$r = $t->send('DELETE', $base . 'x?status=204', [], null, 5);
check($r['status'] === 204 && $r['body'] === '', '204 ohne Rumpf');
$r = $t->send('GET', 'http://127.0.0.1:1/', [], null, 2);
check($r['body'] === false && $r['status'] === 0 && $r['error'] !== '', 'abgelehnte Verbindung: body false mit Fehlertext');
$text = $t->fetchText($base . 'x?status=200', 5, static fn(string $b): bool => str_contains($b, '"method":"GET"'));
check($text !== '', 'fetchText liefert den Text, wenn die Prüfung ihn annimmt');
check($t->fetchText($base . 'x?status=200', 5, static fn(string $b): bool => false) === '', 'und nichts, wenn sie ihn ablehnt');

proc_terminate($proc);
proc_close($proc);
unlink($router);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
