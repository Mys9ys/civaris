<?php

declare(strict_types=1);

use Civaris\Auth;
use Civaris\CatalogService;
use Civaris\Database;
use Civaris\Response;
use Civaris\Schema;
use Civaris\WorldService;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Response.php';
require dirname(__DIR__) . '/src/Auth.php';
require dirname(__DIR__) . '/src/Schema.php';
require dirname(__DIR__) . '/src/CatalogService.php';
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
    Auth::maybeDevAutoAdmin($pdo, $config);
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
$catalog = new CatalogService($pdo);

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
                ['key' => 'families', 'title' => 'Семейства', 'home' => '/catalog'],
                ['key' => 'click-needs', 'title' => 'Потребности щелчка', 'home' => '/catalog'],
                ['key' => 'knowledge', 'title' => 'Ветка знаний', 'home' => '/catalog'],
                ['key' => 'catalog', 'title' => 'Карта калорий (ref_catalog)', 'home' => '/catalog'],
            ],
            'note' => 'UI справочников в /catalog; /settings — только аккаунт. Товары Sheet — /catalog (Товары).',
        ]);
        exit;
    }

    if ($method === 'GET' && $uri === '/catalog/buildings') {
        Auth::requireAdmin($pdo);
        Response::json(['ok' => true, 'items' => $catalog->listBuildings()]);
        exit;
    }

    if ($method === 'POST' && $uri === '/catalog/buildings') {
        Auth::requireAdmin($pdo);
        try {
            $pair = $catalog->upsertBuildingPair($body);
            Response::json(['ok' => true] + $pair, 201);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'PUT' && $uri === '/catalog/buildings') {
        Auth::requireAdmin($pdo);
        try {
            $pair = $catalog->upsertBuildingPair($body);
            Response::json(['ok' => true] + $pair);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    // --- Product catalog (Sheet → ref_sku) ---
    if ($method === 'GET' && $uri === '/catalog/skus') {
        Auth::requireAdmin($pdo);
        $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
        $producer = isset($_GET['producer']) ? (string) $_GET['producer'] : null;
        Response::json(['ok' => true, 'items' => $catalog->listSkus($q, $producer)]);
        exit;
    }

    if ($method === 'POST' && $uri === '/catalog/skus') {
        Auth::requireAdmin($pdo);
        try {
            $row = $catalog->createSku($body);
            Response::json(['ok' => true, 'item' => $row], 201);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if (preg_match('#^/catalog/skus/(\d+)$#', $uri, $m)) {
        Auth::requireAdmin($pdo);
        $id = (int) $m[1];
        if ($method === 'GET') {
            $row = $catalog->getSku($id);
            if (!$row) {
                Response::error('Не найдено', 404);
                exit;
            }
            Response::json(['ok' => true, 'item' => $row]);
            exit;
        }
        if ($method === 'PUT') {
            try {
                $row = $catalog->updateSku($id, $body);
                Response::json(['ok' => true, 'item' => $row]);
            } catch (Throwable $e) {
                Response::error($e->getMessage(), 400);
            }
            exit;
        }
        if ($method === 'DELETE') {
            $catalog->deleteSku($id);
            Response::json(['ok' => true]);
            exit;
        }
    }

    if ($method === 'GET' && $uri === '/catalog/fiscal') {
        Auth::requireAdmin($pdo);
        $worldId = isset($_GET['world_id']) ? (int) $_GET['world_id'] : null;
        Response::json([
            'ok' => true,
            'items' => $catalog->listFiscal($worldId),
            'resolved' => $catalog->resolveFiscal($worldId),
        ]);
        exit;
    }

    if ($method === 'PUT' && $uri === '/catalog/fiscal') {
        Auth::requireAdmin($pdo);
        try {
            $row = $catalog->upsertFiscal($body);
            Response::json(['ok' => true, 'item' => $row]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'GET' && $uri === '/catalog/densities') {
        Auth::requireAdmin($pdo);
        $name = isset($_GET['name']) ? (string) $_GET['name'] : null;
        $productKind = isset($_GET['product_kind']) ? (string) $_GET['product_kind'] : null;
        Response::json([
            'ok' => true,
            'items' => $catalog->listDensities(),
            'suggest' => ($name !== null || $productKind !== null)
                ? $catalog->suggestDensity($name, $productKind)
                : null,
        ]);
        exit;
    }

    if ($method === 'PUT' && $uri === '/catalog/densities') {
        Auth::requireAdmin($pdo);
        try {
            $items = $body['items'] ?? $body;
            if (!is_array($items)) {
                throw new InvalidArgumentException('Нужен массив items');
            }
            $saved = $catalog->saveDensities($items);
            Response::json(['ok' => true, 'items' => $saved]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'POST' && $uri === '/catalog/seed') {
        Auth::requireAdmin($pdo);
        $force = !empty($body['force']);
        try {
            $res = $catalog->seedFromJson($force);
            Response::json(['ok' => true] + $res);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'POST' && $uri === '/catalog/seed-merge') {
        Auth::requireAdmin($pdo);
        try {
            $fillDensity = !array_key_exists('fill_density', $body) || !empty($body['fill_density']);
            $res = $catalog->mergeFillFromSeed($fillDensity);
            Response::json(['ok' => true] + $res);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'GET' && $uri === '/catalog/firms') {
        Auth::requireAdmin($pdo);
        try {
            $catalog->seedFirms(false);
            Response::json(['ok' => true, 'items' => $catalog->listFirms(true)]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'POST' && $uri === '/catalog/firms/seed') {
        Auth::requireAdmin($pdo);
        $force = !empty($body['force']);
        try {
            $res = $catalog->seedFirms($force);
            Response::json(['ok' => true] + $res);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'POST' && $uri === '/catalog/firms/attach-seed') {
        Auth::requireAdmin($pdo);
        try {
            $res = $catalog->attachFirmProductsFromSeed();
            Response::json(['ok' => true] + $res + ['items' => $catalog->listFirms(true)]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if ($method === 'GET' && $uri === '/catalog/maklend/firms') {
        Auth::requireAdmin($pdo);
        try {
            Response::json(['ok' => true] + $catalog->listMaklendCompanies());
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

    if (preg_match('#^/catalog/maklend/firms/(\d+)$#', $uri, $m)) {
        Auth::requireAdmin($pdo);
        $companyId = (int) $m[1];
        if ($method === 'PUT') {
            try {
                $row = $catalog->updateMaklendCompany($companyId, $body);
                Response::json(['ok' => true, 'item' => $row]);
            } catch (Throwable $e) {
                Response::error($e->getMessage(), 400);
            }
            exit;
        }
    }

    if (preg_match('#^/catalog/firms/([^/]+)$#', $uri, $m)) {
        Auth::requireAdmin($pdo);
        $code = rawurldecode($m[1]);
        if ($method === 'GET') {
            $row = $catalog->getFirm($code);
            if (!$row) {
                Response::error('Фирма не найдена', 404);
                exit;
            }
            Response::json(['ok' => true, 'item' => $row]);
            exit;
        }
        if ($method === 'PUT') {
            try {
                $row = $catalog->updateFirm($code, $body);
                if (array_key_exists('products', $body) && is_array($body['products'])) {
                    $row['products'] = $catalog->replaceFirmProducts($code, $body['products']);
                }
                Response::json(['ok' => true, 'item' => $row]);
            } catch (Throwable $e) {
                Response::error($e->getMessage(), 400);
            }
            exit;
        }
    }

    if (preg_match('#^/catalog/firms/([^/]+)/products$#', $uri, $m)) {
        Auth::requireAdmin($pdo);
        $code = rawurldecode($m[1]);
        if ($method === 'GET') {
            Response::json(['ok' => true, 'items' => $catalog->listFirmProducts($code)]);
            exit;
        }
        if ($method === 'PUT') {
            try {
                $items = $body['items'] ?? $body['products'] ?? $body;
                if (!is_array($items)) {
                    throw new InvalidArgumentException('Нужен массив products/items');
                }
                $saved = $catalog->replaceFirmProducts($code, $items);
                Response::json(['ok' => true, 'items' => $saved]);
            } catch (Throwable $e) {
                Response::error($e->getMessage(), 400);
            }
            exit;
        }
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
            'companies' => $worlds->companies($worldId),
            'treasury' => $worlds->treasuryWallet($worldId),
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
        Response::json(['ok' => true, 'chronicle' => $worlds->chronicle((int) $m[1], 200)]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/worlds/(\d+)/settlements/(\d+)/monitor$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $worldId = (int) $m[1];
        $settlementId = (int) $m[2];
        $world = $worlds->getOwned($worldId, $uid);
        if (!$world) {
            Response::error('Мир не найден', 404);
            exit;
        }
        Response::json([
            'ok' => true,
            'world' => $world,
            'monitor' => $worlds->settlementMonitor($worldId, $settlementId),
        ]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/worlds/(\d+)/settlements/(\d+)/layout/canvas/expand$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $worldId = (int) $m[1];
        $settlementId = (int) $m[2];
        $world = $worlds->getOwned($worldId, $uid);
        if (!$world) {
            Response::error('Мир не найден', 404);
            exit;
        }
        $pop = (int) ($world['population'] ?? 30);
        try {
            $layout = $worlds->expandLayoutCanvas($settlementId, $pop, $body);
            Response::json(['ok' => true, 'layout' => $layout]);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 400;
            }
            Response::error($e->getMessage(), $code);
        }
        exit;
    }

    if (preg_match('#^/worlds/(\d+)/settlements/(\d+)/layout/plots(?:/(\d+))?$#', $uri, $m)) {
        $uid = Auth::requireUser();
        $worldId = (int) $m[1];
        $settlementId = (int) $m[2];
        $plotId = isset($m[3]) ? (int) $m[3] : 0;
        $world = $worlds->getOwned($worldId, $uid);
        if (!$world) {
            Response::error('Мир не найден', 404);
            exit;
        }
        $pop = (int) ($world['population'] ?? 30);
        try {
            if ($method === 'POST' && $plotId === 0) {
                $plot = $worlds->createLayoutPlot($settlementId, $pop, $body);
                Response::json([
                    'ok' => true,
                    'plot' => $plot,
                    'layout' => $worlds->ensureAndListLayout($settlementId, $pop),
                ], 201);
                exit;
            }
            if ($method === 'PUT' && $plotId > 0) {
                $plot = $worlds->updateLayoutPlot($settlementId, $plotId, $pop, $body);
                Response::json([
                    'ok' => true,
                    'plot' => $plot,
                    'layout' => $worlds->ensureAndListLayout($settlementId, $pop),
                ]);
                exit;
            }
            if ($method === 'DELETE' && $plotId > 0) {
                $worlds->deleteLayoutPlot($settlementId, $plotId, $pop);
                Response::json([
                    'ok' => true,
                    'layout' => $worlds->ensureAndListLayout($settlementId, $pop),
                ]);
                exit;
            }
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 400;
            }
            Response::error($e->getMessage(), $code);
            exit;
        }
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
            'companies' => $worlds->companies($worldId),
            'treasury' => $worlds->treasuryWallet($worldId),
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
