<?php
// main.php — single-file PHP REST API (Todos)
// Run with: php -S localhost:8000 main.php

declare(strict_types=1);

// ---- Basic CORS & preflight ----
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Expose-Headers: Location');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---- "Database" file ----
const DB_FILE = __DIR__ . '/data.json';

// ---- Helpers ----
function send($data, int $status = 200, array $extraHeaders = []): void {
    header('Content-Type: application/json; charset=utf-8');
    foreach ($extraHeaders as $k => $v) header("$k: $v");
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): void {
    send(['error' => $message, 'status' => $status], $status);
}

function read_db(): array {
    if (!file_exists(DB_FILE)) {
        file_put_contents(DB_FILE, json_encode(['todos' => []], JSON_PRETTY_PRINT));
    }
    $fp = fopen(DB_FILE, 'r');
    if (!$fp) fail('Cannot open database.', 500);
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($json ?: '[]', true);
    return is_array($data) ? $data : ['todos' => []];
}

function write_db(array $data): void {
    $tmp = DB_FILE . '.tmp';
    $fp = fopen($tmp, 'c+');
    if (!$fp) fail('Cannot open database for writing.', 500);
    if (!flock($fp, LOCK_EX)) { fclose($fp); fail('Cannot lock database.', 500); }
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    rename($tmp, DB_FILE);
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) fail('Invalid JSON body.', 400);
    return $data ?? [];
}

function next_id(array $items): int {
    $max = 0;
    foreach ($items as $it) $max = max($max, (int)($it['id'] ?? 0));
    return $max + 1;
}

// ---- Routing ----
$method   = $_SERVER['REQUEST_METHOD'];
$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

if (count($segments) === 0) {
    send(['message' => 'Todo API up. Use /todos'], 200);
}

if ($segments[0] !== 'todos') {
    fail('Not found.', 404);
}

$db    = read_db();
$todos = $db['todos'] ?? [];

// GET /todos
if ($method === 'GET' && count($segments) === 1) {
    $q      = isset($_GET['q']) ? (string)$_GET['q'] : '';
    $limit  = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $filtered = array_values(array_filter($todos, function ($t) use ($q) {
        if ($q === '') return true;
        return stripos($t['title'] ?? '', $q) !== false;
    }));
    $paged = array_slice($filtered, $offset, $limit);

    send(['data' => $paged, 'total' => count($filtered), 'limit' => $limit, 'offset' => $offset]);
}

// GET /todos/{id}
if ($method === 'GET' && count($segments) === 2) {
    $id = (int)$segments[1];
    foreach ($todos as $t) {
        if ((int)$t['id'] === $id) send($t);
    }
    fail('Todo not found.', 404);
}

// POST /todos
if ($method === 'POST' && count($segments) === 1) {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        fail('Content-Type must be application/json.', 415);
    }
    $in    = json_input();
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') fail('Field "title" is required.', 422);

    $todo = [
        'id'         => next_id($todos),
        'title'      => $title,
        'done'       => (bool)($in['done'] ?? false),
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $todos[]       = $todo;
    $db['todos']   = $todos;
    write_db($db);

    send($todo, 201, ['Location' => '/todos/' . $todo['id']]);
}

// PUT/PATCH /todos/{id}
if (($method === 'PUT' || $method === 'PATCH') && count($segments) === 2) {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        fail('Content-Type must be application/json.', 415);
    }
    $id = (int)$segments[1];
    $in = json_input();

    $updated = false;
    foreach ($todos as &$t) {
        if ((int)$t['id'] === $id) {
            if (array_key_exists('title', $in)) {
                $newTitle = trim((string)$in['title']);
                if ($newTitle === '') fail('Field "title" cannot be empty.', 422);
                $t['title'] = $newTitle;
                $updated = true;
            }
            if (array_key_exists('done', $in)) {
                $t['done'] = (bool)$in['done'];
                $updated = true;
            }
            if (!$updated) fail('No updatable fields provided.', 400);
            $t['updated_at'] = gmdate('c');

            $db['todos'] = $todos;
            write_db($db);
            send($t);
        }
    }
    unset($t);
    fail('Todo not found.', 404);
}

// DELETE /todos/{id}
if ($method === 'DELETE' && count($segments) === 2) {
    $id    = (int)$segments[1];
    $found = false;
    foreach ($todos as $i => $t) {
        if ((int)$t['id'] === $id) { array_splice($todos, $i, 1); $found = true; break; }
    }
    if (!$found) fail('Todo not found.', 404);

    $db['todos'] = array_values($todos);
    write_db($db);
    send(['deleted' => $id], 200);
}

// Fallback
fail('Method not allowed.', 405);
