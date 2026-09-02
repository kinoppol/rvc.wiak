<?php
/**
 * Open Authenticator (SSO) connection settings.
 *
 * These are NOT secrets — this flow has no client_secret. The browser already
 * sees client_id and redirect_uri, and token verification is a server-to-server
 * call keyed only by the one-time token_key handed back through the callback.
 * So they live in this committed file (not the git-ignored config/config.php),
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
];
