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
        $roles = User::rolesFor((int) $user['id']);
        $_SESSION['active_role'] = $roles[0] ?? null;
        unset($_SESSION['impersonating']);
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
        return $roles[0] ?? null;
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
        $roles = User::rolesFor($targetUserId);
        $_SESSION['active_role'] = $roles[0] ?? null;
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
            $roles = User::rolesFor((int) $real['id']);
            $_SESSION['active_role'] = $roles[0] ?? null;
            if ($impersonated) {
                AuditLog::record((int) $real['id'], (int) $impersonated['id'], 'impersonate.stop', 'user', (int) $impersonated['id']);
            }
        }
    }
}
