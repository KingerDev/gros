<?php

namespace Tests\Feature;

use App\Services\AppleIdentity;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

/** Overenie Apple identity tokenu proti JWKS (s vlastným testovacím RSA kľúčom). */
class AppleIdentityTest extends TestCase
{
    protected string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        Http::fake([AppleIdentity::KEYS_URL => Http::response(['keys' => [[
            'kty' => 'RSA', 'kid' => 'test-kid', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $b64($rsa['n']), 'e' => $b64($rsa['e']),
        ]]])]);

        config(['services.apple.client_id' => 'com.martinbalaz.gros']);
    }

    /** @param  array<string, mixed>  $claims */
    protected function token(array $claims = [], string $kid = 'test-kid'): string
    {
        return JWT::encode(array_merge([
            'iss' => AppleIdentity::ISSUER,
            'aud' => 'com.martinbalaz.gros',
            'sub' => '001234.abcd',
            'email' => 'Jano@PrivateRelay.AppleID.com',
            'email_verified' => 'true',
            'iat' => time(),
            'exp' => time() + 600,
        ], $claims), $this->privateKey, 'RS256', $kid);
    }

    public function test_valid_token_returns_sub_and_email(): void
    {
        $this->assertSame(
            ['sub' => '001234.abcd', 'email' => 'jano@privaterelay.appleid.com'],
            app(AppleIdentity::class)->verify($this->token()),
        );
    }

    public function test_jwks_is_cached(): void
    {
        $apple = app(AppleIdentity::class);
        $apple->verify($this->token());
        $apple->verify($this->token());

        Http::assertSentCount(1);
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        app(AppleIdentity::class)->verify($this->token(['aud' => 'com.someone.else']));
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        app(AppleIdentity::class)->verify($this->token(['iss' => 'https://evil.example.com']));
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        app(AppleIdentity::class)->verify($this->token(['exp' => time() - 3600]));
    }

    public function test_foreign_signature_is_rejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);
        $token = JWT::encode(['iss' => AppleIdentity::ISSUER, 'aud' => 'com.martinbalaz.gros', 'sub' => 'x', 'exp' => time() + 600], $pem, 'RS256', 'test-kid');

        $this->expectException(UnexpectedValueException::class);
        app(AppleIdentity::class)->verify($token);
    }

    public function test_unknown_kid_refetches_keys_once(): void
    {
        $this->expectException(UnexpectedValueException::class);

        try {
            app(AppleIdentity::class)->verify($this->token(kid: 'rotated'));
        } finally {
            Http::assertSentCount(2);
        }
    }

    public function test_garbage_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        app(AppleIdentity::class)->verify('not-a-jwt');
    }

    public function test_unverified_email_is_ignored(): void
    {
        $this->assertNull(app(AppleIdentity::class)->verify($this->token(['email_verified' => false]))['email']);
    }

    public function test_without_apple_key_code_exchange_and_revoke_are_skipped(): void
    {
        config(['services.apple.team_id' => null, 'services.apple.key_id' => null, 'services.apple.private_key' => null]);
        $apple = app(AppleIdentity::class);

        $this->assertNull($apple->refreshToken('code'));
        $apple->revoke('token');

        Http::assertNothingSent();
    }

    public function test_code_exchange_signs_client_secret_with_p8(): void
    {
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($ec, $p8);
        config(['services.apple.team_id' => 'TEAM123', 'services.apple.key_id' => 'KEY123', 'services.apple.private_key' => str_replace("\n", '\n', $p8)]);
        Http::fake([AppleIdentity::TOKEN_URL => Http::response(['refresh_token' => 'r-token', 'access_token' => 'a'])]);

        $this->assertSame('r-token', app(AppleIdentity::class)->refreshToken('code-1'));

        Http::assertSent(function (Request $request) {
            if ($request->url() !== AppleIdentity::TOKEN_URL) {
                return false;
            }
            [$header, $payload] = array_map(fn ($p) => json_decode(JWT::urlsafeB64Decode($p), true), array_slice(explode('.', $request['client_secret']), 0, 2));

            return $request['code'] === 'code-1'
                && $request['grant_type'] === 'authorization_code'
                && $header['alg'] === 'ES256' && $header['kid'] === 'KEY123'
                && $payload['iss'] === 'TEAM123' && $payload['sub'] === 'com.martinbalaz.gros';
        });
    }
}
