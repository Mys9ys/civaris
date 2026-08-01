<?php

declare(strict_types=1);

use Civaris\Auth;
use Civaris\Database;
use Civaris\Response;
use Civaris\WorldService;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Response.php';
require dirname(__DIR__) . '/src/Auth.php';
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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

Auth::startSession();

try {
    $pdo = Database::pdo($config);
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
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $body = is_array($json) ? $json : $_POST;
}

$worlds = new WorldService($pdo, $config['app']);

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
        $world = $worlds->create($uid, $name);
        Response::json(['ok' => true, 'world' => $world]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/worlds/(\d+)$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $world = $worlds->getOwned((int) $m[1], $uid);
        if (!$world) {
            Response::error('Мир не найден', 404);
            exit;
        }
        Response::json([
            'ok' => true,
            'world' => $world,
            'settlements' => $worlds->settlements((int) $m[1]),
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
            'chronicle' => $worlds->chronicle((int) $m[1], 20),
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
