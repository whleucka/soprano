<?php

namespace App\Services\Auth\Client;

use App\Models\Client;
use App\Models\ClientRememberToken;

/**
 * Persistent "remember me" login for clients.
 *
 * Cookie holds "selector:validator"; only a sha256 of the validator is
 * stored server-side. One token row per device/browser.
 */
class RememberTokenService
{
    public const COOKIE = "soprano_remember";
    private const LIFETIME = 60 * 60 * 24 * 365; // 1 year

    /**
     * Issue a fresh token for this device and set the cookie.
     */
    public function issue(Client $client): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        ClientRememberToken::create([
            "client_id" => $client->id,
            "selector" => $selector,
            "validator_hash" => hash("sha256", $validator),
            "expires_at" => time() + self::LIFETIME,
        ]);

        $this->setCookie($selector . ":" . $validator, time() + self::LIFETIME);
        $this->pruneExpired();
    }

    /**
     * Attempt to sign in from the remember cookie. On success the session
     * is established and the token's expiry is extended.
     */
    public function attempt(): ?Client
    {
        $parsed = $this->parseCookie();
        if (!$parsed) {
            return null;
        }
        [$selector, $validator] = $parsed;

        $token = ClientRememberToken::where("selector", $selector)->first();
        if (!$token || (int) $token->expires_at < time()) {
            $token?->delete();
            $this->clearCookie();
            return null;
        }

        if (!hash_equals($token->validator_hash, hash("sha256", $validator))) {
            // Valid selector with a bad validator means the cookie was
            // stolen or tampered with — kill this token.
            $token->delete();
            $this->clearCookie();
            return null;
        }

        $client = Client::find($token->client_id);
        if (!$client) {
            $token->delete();
            $this->clearCookie();
            return null;
        }

        session()->regenerate();
        session()->set("client_uuid", $client->uuid);

        // Restored session starts empty — seed the queue with the client's
        // saved shuffle/repeat defaults, same as a fresh sign-in.
        container()->get(\App\Services\Soprano\PlaylistService::class)->applyDefaults(
            (bool) ($client->default_shuffle ?? false),
            $client->default_repeat ?? 'off',
        );

        // Sliding expiry: stays signed in as long as the app gets used
        // at least once a year on this device.
        $token->update(["expires_at" => time() + self::LIFETIME]);
        $this->setCookie($selector . ":" . $validator, time() + self::LIFETIME);

        return $client;
    }

    /**
     * Revoke this device's token and clear the cookie (sign-out).
     */
    public function forget(): void
    {
        $parsed = $this->parseCookie();
        if ($parsed) {
            ClientRememberToken::where("selector", $parsed[0])->first()?->delete();
        }
        $this->clearCookie();
    }

    private function parseCookie(): ?array
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (!$raw || substr_count($raw, ":") !== 1) {
            return null;
        }
        [$selector, $validator] = explode(":", $raw);
        if (strlen($selector) !== 24 || strlen($validator) !== 64) {
            return null;
        }
        return [$selector, $validator];
    }

    private function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            "expires" => $expires,
            "path" => "/",
            "secure" => isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
            "httponly" => true,
            "samesite" => "Lax",
        ]);
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE, "", [
            "expires" => time() - 3600,
            "path" => "/",
            "secure" => isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    private function pruneExpired(): void
    {
        db()->execute("DELETE FROM client_remember_tokens WHERE expires_at < ?", [time()]);
    }
}
