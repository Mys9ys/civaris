<?php

declare(strict_types=1);

use Civaris\Auth;
use Civaris\Database;
use Civaris\Response;
use Civaris\Schema;
use Civaris\WorldService;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Response.php';
require dirname(__DIR__) . '/src/Auth.php';
require dirname(__DIR__) . '/src/Schema.php';
require dirname(__DIR__) . '/src/WorldService.php';

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    Response::error('Создайте api/config/config.php из config.example.php', 500);
    exit;
}

/** @var array $config */
$config = require $configFile;

header('Access-Control-Allow-Origin: ' . ($config['app']['cors_origin'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

Auth::startSession();

try {
    $pdo = Database::pdo($config);
    Schema::ensure($pdo);
} catch (Throwable $e) {
    Response::error($config['app']['debug'] ? $e->getMessage() : 'Ошибка БД', 500);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Support /api/... when fronted by public/api rewrite
$uri = preg_replace('#^/api#', '', $uri) ?: '/';
$uri = rtrim($uri, '/') ?: '/';

$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $body = is_array($json) ? $json : $_POST;
}

require dirname(__DIR__) . '/src/AdminService.php';

$worlds = new WorldService($pdo, $config['app']);
$admin = new \Civaris\AdminService($pdo);

try {
    if ($method === 'GET' && $uri === '/health') {
        Response::json(['ok' => true, 'app' => $config['app']['name']]);
        exit;
    }

    if ($method === 'GET' && $uri === '/me') {
        Response::json(['ok' => true, 'user' => Auth::me($pdo)]);
        exit;
    }

    if ($method === 'POST' && $uri === '/auth/register') {
        $user = Auth::register(
            $pdo,
            (string) ($body['login'] ?? ''),
            (string) ($body['password'] ?? ''),
            isset($body['email']) ? (string) $body['email'] : null
        );
        Response::json(['ok' => true, 'user' => $user]);
        exit;
    }

    if ($method === 'POST' && $uri === '/auth/login') {
        $user = Auth::login(
            $pdo,
            (string) ($body['login'] ?? ''),
            (string) ($body['password'] ?? '')
        );
        Response::json(['ok' => true, 'user' => $user]);
        exit;
    }

    if ($method === 'POST' && $uri === '/auth/vk-mock') {
        $vkId = (int) ($body['vk_id'] ?? 0);
        if ($vkId <= 0) {
            Response::error('vk_id обязателен');
            exit;
        }
        $user = Auth::loginVkMock(
            $pdo,
            $vkId,
            isset($body['email']) ? (string) $body['email'] : null
        );
        Response::json(['ok' => true, 'user' => $user, 'mock' => true]);
        exit;
    }

    if ($method === 'POST' && $uri === '/auth/logout') {
        Auth::logout();
        Response::json(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $uri === '/auth/password') {
        $uid = Auth::requireUser();
        Auth::changePassword(
            $pdo,
            $uid,
            (string) ($body['current_password'] ?? ''),
            (string) ($body['new_password'] ?? '')
        );
        Response::json(['ok' => true]);
        exit;
    }

    // --- Admin settings CRUD ---
    if ($method === 'GET' && $uri === '/settings/meta') {
        Auth::requireAdmin($pdo);
        Response::json([
            'ok' => true,
            'resources' => [
                ['key' => 'families', 'title' => 'Семейства'],
                ['key' => 'click-needs', 'title' => 'Потребности щелчка'],
                ['key' => 'catalog', 'title' => 'Каталог товаров v0'],
                ['key' => 'knowledge', 'title' => 'Ветка знаний'],
            ],
        ]);
        exit;
    }

    if (preg_match('#^/settings/(families|click-needs|catalog|knowledge)$#', $uri, $m)) {
        Auth::requireAdmin($pdo);
        $resource = $m[1];
        if ($method === 'GET') {
            Response::json(['ok' => true, 'items' => $admin->list($resource)]);
            exit;
        }
        if ($method === 'POST') {
            $row = $admin->create($resource, $body);
            Response::json(['ok' => true, 'item' => $row], 201);
            exit;
        }
    }

    if (preg_match('#^/settings/(families|click-needs|catalog|knowledge)/(\d+)$#', $uri, $m)) {
        Auth::requireAdmin($pdo);
        $resource = $m[1];
        $id = (int) $m[2];
        if ($method === 'PUT') {
            $row = $admin->update($resource, $id, $body);
            Response::json(['ok' => true, 'item' => $row]);
            exit;
        }
        if ($method === 'DELETE') {
            $admin->delete($resource, $id);
            Response::json(['ok' => true]);
            exit;
        }
    }

    if ($method === 'GET' && $uri === '/worlds') {
        $uid = Auth::requireUser();
        Response::json(['ok' => true, 'worlds' => $worlds->listForUser($uid)]);
        exit;
    }

    if ($method === 'POST' && $uri === '/worlds') {
        $uid = Auth::requireUser();
        $name = trim((string) ($body['name'] ?? 'Мир Civaris'));
        if ($name === '') {
            $name = 'Мир Civaris';
        }
        $mapSeed = isset($body['map_seed']) ? (int) $body['map_seed'] : null;
        if ($mapSeed !== null && $mapSeed <= 0) {
            $mapSeed = null;
        }
        $world = $worlds->create($uid, $name, $mapSeed);
        Response::json(['ok' => true, 'world' => $world], 201);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/worlds/(\d+)$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $worldId = (int) $m[1];
        $world = $worlds->getOwned($worldId, $uid);
        if (!$world) {
            Response::error('Мир не найден', 404);
            exit;
        }
        Response::json([
            'ok' => true,
            'world' => $world,
            'settlements' => $worlds->settlements($worldId),
            'people_count' => $worlds->peopleCount($worldId),
        ]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/worlds/(\d+)/chronicle$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $world = $worlds->getOwned((int) $m[1], $uid);
        if (!$world) {
            Response::error('Мир не найден', 404);
            exit;
        }
        Response::json(['ok' => true, 'chronicle' => $worlds->chronicle((int) $m[1])]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/worlds/(\d+)/advance-day$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $world = $worlds->advanceDay((int) $m[1], $uid);
        Response::json([
            'ok' => true,
            'world' => $world,
            'chronicle' => $worlds->chronicle((int) $m[1], 60),
        ]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/worlds/(\d+)/reset$#', $uri, $m)) {
        $uid = Auth::requireAdmin($pdo);
        $worldId = (int) $m[1];
        $world = $worlds->reset($worldId, $uid);
        Response::json([
            'ok' => true,
            'world' => $world,
            'settlements' => $worlds->settlements($worldId),
            'people_count' => $worlds->peopleCount($worldId),
            'chronicle' => $worlds->chronicle($worldId, 60),
        ]);
        exit;
    }

    Response::error('Не найдено: ' . $uri, 404);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    Response::error($config['app']['debug'] ? $e->getMessage() : 'Ошибка сервера', $code);
}
