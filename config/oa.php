<?php
/**
 * RVC-ONE (Open Authenticator) connection settings — SSO login + User-sync API.
 *
 * These are NOT secrets — the browser already sees client_id and redirect_uri.
 * The one secret, client_secret (HTTP Basic auth for both verify_token and the
 * User-sync API), is never committed: see 'users_url' below.
 * So these live in this committed file (not the git-ignored config/config.php),
 * which means every install has them without editing anything.
 *
 * To override a value for a non-standard deployment, add an 'oa' => [...] block
 * to config/config.php — bootstrap.php merges it over these defaults.
 *
 * See App\Core\Oa and CLAUDE.md ("Open Authenticator SSO login") for the flow.
 */
return [
    // Where the browser is sent to authenticate (password + optional OTP +
    // first-time consent screen).
    'authorize_url' => 'http://workspace.rvc.ac.th/oa/index.php',

    // Server-to-server endpoint that turns a callback token into a user object.
    'verify_token_url' => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',

    // Registered with the gateway for this app.
    'client_id' => 'wiak',

    // MUST match the value registered with the gateway byte-for-byte
    // (scheme, path, punctuation, no trailing slash). The gateway POSTs the
    // callback here; our router maps this exact path to OaAuthController.
    'redirect_uri' => 'https://wiak.rvc.ac.th/web/api/callback.php',

    // Seconds to wait on the verify_token call before giving up (the gateway's
    // tokens live only 5 minutes, so a slow verify is a failed login).
    'http_timeout' => 8,

    // User-sync API (server-to-server, see App\Services\OaUserSync). It DOES
    // need a client_secret — that one is a real secret and must never be
    // committed here: set the OA_CLIENT_SECRET environment variable, or add
    // 'oa' => ['client_secret' => '...'] to the git-ignored config/config.php.
    'users_url' => 'http://workspace.rvc.ac.th/oa/api/users.php',

    // User types this app is licensed for at the gateway (staff | student | all).
    'sync_type' => 'staff',

    // Users per page (1–1000).
    'sync_page_size' => 500,
];
