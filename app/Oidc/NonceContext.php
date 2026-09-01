<?php

namespace App\Oidc;

/**
 * Request-scoped holder that bridges the OIDC nonce from the token endpoint's
 * authorization_code lookup to the custom ResponseType that builds the ID token.
 * League's grant classes give us no hook to pass this through directly.
 */
class NonceContext
{
    private static ?string $nonce = null;

    public static function set(?string $nonce): void
    {
        self::$nonce = $nonce;
    }

    public static function get(): ?string
    {
        return self::$nonce;
    }

    public static function clear(): void
    {
        self::$nonce = null;
    }
}
