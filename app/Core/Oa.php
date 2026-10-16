<?php
declare(strict_types=1);

namespace App\Core;

/**
 * RVC-ONE (Open Authenticator) SSO client.
 *
 * Flow: /auth/oa/start mints a random `state` into the session and sends the
 * browser to the gateway's authorize_url. The gateway authenticates the user
 * (password, optional OTP, first-time consent) and POSTs token_id + token_key
 * + state back to redirect_uri (mapped to OaAuthController::callback). We
 * re-check state, then verify the token server-to-server against
 * verify_token_url — token_id embeds a user id but is never trusted on its
 * own. A {"valid":true} response carries the 5-field user object we build a
 * local session from.
 *
 * Config comes from config/oa.php (see bootstrap.php).
 */
final class Oa
{
    /** Keys an admin may override from /admin/settings (stored as `oa_<key>`). */
    public const OVERRIDABLE = ['authorize_url', 'verify_token_url', 'client_id', 'redirect_uri'];

    /** Default caption for the login-page SSO button (admin-overridable). */
    public const DEFAULT_BUTTON_LABEL = 'ลงชื่อเข้าใช้ผ่านระบบ RVC-ONE';

    /** @var array<string,mixed> file defaults from config/oa.php */
    private static array $cfg = [];

    /** @var array<string,mixed>|null file defaults + settings-table overrides, per request */
    private static ?array $merged = null;

    /** @param array<string,mixed> $cfg */
    public static function configure(array $cfg): void
    {
        self::$cfg = $cfg;
        self::$merged = null;
    }

    /**
     * File defaults with any admin overrides from the `settings` table layered
     * on top. Falls back to file config alone if the settings table isn't there
     * yet (fresh install, before migrations).
     *
     * @return array<string,mixed>
     */
    private static function merged(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }
        $out = self::$cfg;
        try {
            foreach (self::OVERRIDABLE as $key) {
                $v = \App\Models\Setting::get('oa_' . $key);
                if ($v !== null && $v !== '') {
                    $out[$key] = $v;
                }
            }
            $enabled = \App\Models\Setting::get('oa_enabled');
            $out['enabled'] = $enabled === null ? true : $enabled === '1';
            $label = \App\Models\Setting::get('oa_button_label');
            $out['button_label'] = ($label !== null && trim($label) !== '') ? $label : self::DEFAULT_BUTTON_LABEL;
        } catch (\Throwable $e) {
            $out['enabled'] = $out['enabled'] ?? true;
            $out['button_label'] = $out['button_label'] ?? self::DEFAULT_BUTTON_LABEL;
        }
        return self::$merged = $out;
    }

    public static function get(string $key): string
    {
        return (string) (self::merged()[$key] ?? '');
    }

    /** Whether SSO login is offered at all (admin toggle at /admin/settings). */
    public static function isEnabled(): bool
    {
        return (bool) (self::merged()['enabled'] ?? true);
    }

    /** Caption for the login-page SSO button (admin-overridable). */
    public static function buttonLabel(): string
    {
        $label = (string) (self::merged()['button_label'] ?? '');
        return $label !== '' ? $label : self::DEFAULT_BUTTON_LABEL;
    }

    /** URL to send the browser to, carrying our anti-CSRF state. */
    public static function authorizeUrl(string $state): string
    {
        return self::get('authorize_url') . '?' . http_build_query([
            'client_id'    => self::get('client_id'),
            'redirect_uri' => self::get('redirect_uri'),
            'state'        => $state,
        ]);
    }

    /**
     * Verify a callback token with the gateway. Server-side only — never call
     * this from, or expose its inputs to, the browser.
     *
     * @return array{id:int,email:string,first_name:string,last_name:string,department:string,national_id:string}|null
     *         the user object on {"valid":true}; null on {"valid":false}, a
     *         network/timeout failure, a missing curl extension, or any
     *         malformed response.
     */
    public static function verifyToken(string $tokenId, string $tokenKey): ?array
    {
        if ($tokenId === '' || $tokenKey === '' || self::clientSecret() === '' || !function_exists('curl_init')) {
            return null;
        }

        $timeout = (int) (self::merged()['http_timeout'] ?? 8);
        $ch = curl_init(self::get('verify_token_url'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'token_id'  => $tokenId,
                'token_key' => $tokenKey,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            // The gateway authenticates the calling app with HTTP Basic
            // client_id:client_secret (401 invalid_client without it).
            CURLOPT_USERPWD        => self::get('client_id') . ':' . self::clientSecret(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, min(5, $timeout)),
            CURLOPT_TIMEOUT        => max(3, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Don't log token_id / token_key. A transport failure is just a failed
        // login — the caller turns null into a 401.
        if ($errno !== 0 || !is_string($body)) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || ($data['valid'] ?? null) !== true || !is_array($data['user'] ?? null)) {
            return null;
        }

        return self::normalizeUser($data['user']);
    }

    /**
     * Pick the fields we use out of a gateway user object (from verify_token
     * or the users.php sync API — same shape). Only id/name/department are
     * guaranteed; everything else depends on what the gateway admin granted
     * this app, so every other field is optional.
     *
     * @param array<string,mixed> $u
     * @return array{id:int,email:string,first_name:string,last_name:string,department:string,national_id:string}|null
     */
    public static function normalizeUser(array $u): ?array
    {
        if (!isset($u['id']) || !is_numeric($u['id']) || (int) $u['id'] <= 0) {
            return null;
        }
        return [
            'id'          => (int) $u['id'],
            'email'       => trim((string) ($u['email'] ?? '')),
            'first_name'  => trim((string) ($u['first_name'] ?? '')),
            'last_name'   => trim((string) ($u['last_name'] ?? '')),
            'department'  => trim((string) ($u['department'] ?? '')),
            'national_id' => (string) preg_replace('/\D/', '', (string) ($u['national_id'] ?? '')),
        ];
    }

    /**
     * client_secret for server-to-server calls (verify_token and the User-sync
     * API, both HTTP Basic client_id:client_secret). A real secret, so
     * it never lives in a committed file: the OA_CLIENT_SECRET environment
     * variable wins, else 'oa' => ['client_secret' => ...] in the git-ignored
     * config/config.php.
     */
    public static function clientSecret(): string
    {
        $env = getenv('OA_CLIENT_SECRET');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return (string) (self::$cfg['client_secret'] ?? '');
    }

    /** HTTP timeout (seconds) for server-to-server calls to the gateway. */
    public static function httpTimeout(): int
    {
        return max(3, (int) (self::merged()['http_timeout'] ?? 8));
    }
}
