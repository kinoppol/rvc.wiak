<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\AuditLog;
use App\Models\User;

final class Auth
{
    /**
     * Real, logged-in identity. Never changes while impersonating.
     */
    public static function realUser(): ?array
    {
        $id = $_SESSION['real_user_id'] ?? null;
        return $id ? User::findById((int) $id) : null;
    }

    /**
     * Effective identity for the request: the impersonated user if an
     * admin is currently impersonating, otherwise the real user.
     */
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        return $id ? User::findById((int) $id) : null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['real_user_id'] = (int) $user['id'];
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['active_role'] = User::defaultRole($user);
        unset($_SESSION['impersonating']);
    }

    /** Where to land after login. Callers pass a path they built themselves. */
    public static function rememberReturn(string $path): void
    {
        $_SESSION['return_to'] = $path;
    }

    /** The remembered path, once. Anything but a same-site path falls back to /. */
    public static function pullReturn(): string
    {
        $path = (string) ($_SESSION['return_to'] ?? '');
        unset($_SESSION['return_to']);
        return preg_match('#^/(?![/\\\\])[^\r\n\\\\]*$#', $path) ? $path : '/';
    }

    /** Keep this session valid when the account it points at was merged away. */
    public static function remapMergedUser(int $sourceId, int $targetId): void
    {
        foreach (['real_user_id', 'user_id'] as $key) {
            if ((int) ($_SESSION[$key] ?? 0) === $sourceId) {
                $_SESSION[$key] = $targetId;
            }
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function roles(): array
    {
        $u = self::user();
        return $u ? User::rolesFor((int) $u['id']) : [];
    }

    public static function activeRole(): ?string
    {
        $role = $_SESSION['active_role'] ?? null;
        $roles = self::roles();
        if ($role && in_array($role, $roles, true)) {
            return $role;
        }
        $user = self::user();
        return $user ? User::defaultRole($user) : null;
    }

    public static function setActiveRole(string $role): bool
    {
        if (!in_array($role, self::roles(), true)) {
            return false;
        }
        $_SESSION['active_role'] = $role;
        return true;
    }

    public static function isImpersonating(): bool
    {
        return !empty($_SESSION['impersonating']);
    }

    public static function impersonate(int $targetUserId): bool
    {
        $real = self::realUser();
        if (!$real || !in_array('admin', User::rolesFor((int) $real['id']), true)) {
            return false;
        }
        $target = User::findById($targetUserId);
        if (!$target) {
            return false;
        }
        $_SESSION['user_id'] = $targetUserId;
        $_SESSION['impersonating'] = true;
        $_SESSION['active_role'] = User::defaultRole($target);
        AuditLog::record((int) $real['id'], $targetUserId, 'impersonate.start', 'user', $targetUserId);
        return true;
    }

    public static function stopImpersonating(): void
    {
        $real = self::realUser();
        $impersonated = self::user();
        if ($real) {
            $_SESSION['user_id'] = (int) $real['id'];
            unset($_SESSION['impersonating']);
            $_SESSION['active_role'] = User::defaultRole($real);
            if ($impersonated) {
                AuditLog::record((int) $real['id'], (int) $impersonated['id'], 'impersonate.stop', 'user', (int) $impersonated['id']);
            }
        }
    }
}
