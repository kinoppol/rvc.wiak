<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Open Authenticator (SSO) client.
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
    /** @var array<string,mixed> */
    private static array $cfg = [];

    /** @param array<string,mixed> $cfg */
    public static function configure(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function get(string $key): string
    {
        return (string) (self::$cfg[$key] ?? '');
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
     * @return array{id:int,email:string,first_name:string,last_name:string,department:string}|null
     *         the user object on {"valid":true}; null on {"valid":false}, a
     *         network/timeout failure, a missing curl extension, or any
     *         malformed response.
     */
    public static function verifyToken(string $tokenId, string $tokenKey): ?array
    {
        if ($tokenId === '' || $tokenKey === '' || !function_exists('curl_init')) {
            return null;
        }

        $timeout = (int) (self::$cfg['http_timeout'] ?? 8);
        $ch = curl_init(self::get('verify_token_url'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'token_id'  => $tokenId,
                'token_key' => $tokenKey,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
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

        $u = $data['user'];
        if (!isset($u['id']) || !is_numeric($u['id'])) {
            return null;
        }

        return [
            'id'         => (int) $u['id'],
            'email'      => trim((string) ($u['email'] ?? '')),
            'first_name' => trim((string) ($u['first_name'] ?? '')),
            'last_name'  => trim((string) ($u['last_name'] ?? '')),
            'department' => trim((string) ($u['department'] ?? '')),
        ];
    }
}
