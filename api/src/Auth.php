<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function userId(): ?int
    {
        self::startSession();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function requireUser(): int
    {
        $id = self::userId();
        if ($id === null) {
            Response::error('Требуется вход', 401);
            exit;
        }
        return $id;
    }

    public static function register(PDO $pdo, string $login, string $password, ?string $email = null): array
    {
        $login = trim($login);
        if ($login === '' || strlen($password) < 6) {
            throw new \InvalidArgumentException('Логин обязателен, пароль от 6 символов');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (login, password_hash, email) VALUES (?, ?, ?)');
        $stmt->execute([$login, $hash, $email]);
        $id = (int) $pdo->lastInsertId();
        self::startSession();
        $_SESSION['user_id'] = $id;
        return ['id' => $id, 'login' => $login, 'email' => $email];
    }

    public static function login(PDO $pdo, string $login, string $password): array
    {
        $stmt = $pdo->prepare('SELECT id, login, email, password_hash FROM users WHERE login = ? LIMIT 1');
        $stmt->execute([trim($login)]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            throw new \InvalidArgumentException('Неверный логин или пароль');
        }
        self::startSession();
        $_SESSION['user_id'] = (int) $row['id'];
        return ['id' => (int) $row['id'], 'login' => $row['login'], 'email' => $row['email']];
    }

    /** Mock VK: creates/links user by vk_id without real signature check (dev only). */
    public static function loginVkMock(PDO $pdo, int $vkId, ?string $email = null): array
    {
        $stmt = $pdo->prepare('SELECT id, login, email, vk_id FROM users WHERE vk_id = ? LIMIT 1');
        $stmt->execute([$vkId]);
        $row = $stmt->fetch();
        if ($row) {
            self::startSession();
            $_SESSION['user_id'] = (int) $row['id'];
            return ['id' => (int) $row['id'], 'login' => $row['login'], 'email' => $row['email'], 'vk_id' => (int) $row['vk_id']];
        }

        $login = 'vk_' . $vkId;
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (login, password_hash, email, vk_id) VALUES (?, ?, ?, ?)');
        $ins->execute([$login, $hash, $email, $vkId]);
        $id = (int) $pdo->lastInsertId();
        self::startSession();
        $_SESSION['user_id'] = $id;
        return ['id' => $id, 'login' => $login, 'email' => $email, 'vk_id' => $vkId];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function me(PDO $pdo): ?array
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, login, email, vk_id, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
