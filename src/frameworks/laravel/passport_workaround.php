<?php
/**
 * DDLess Passport Workaround
 *
 * When running under DDLess (PHP CLI), Laravel Passport's PersonalAccessTokenFactory
 * makes an HTTP request to /oauth/token using Guzzle. This fails because there's
 * no HTTP server running in the CLI process.
 *
 * This workaround replaces PersonalAccessTokenFactory with a version that
 * generates tokens directly without making HTTP requests.
 */

namespace DDLess\Workarounds;

use Illuminate\Contracts\Foundation\Application;

/**
 * Check if we should apply Passport workarounds
 */
function shouldApplyPassportWorkaround(): bool
{
    return php_sapi_name() === 'cli' || getenv('DDLESS_DEBUG_MODE') === 'true';
}

/**
 * Register Passport workarounds with the Laravel application
 */
function registerPassportWorkaround(Application $app): void
{
    if (!shouldApplyPassportWorkaround()) {
        return;
    }

    // Check if Passport is installed
    if (!class_exists('Laravel\Passport\Passport')) {
        return;
    }

    fwrite(STDERR, "[ddless] Passport detected - replacing PersonalAccessTokenFactory...\n");

    try {
        // Replace the PersonalAccessTokenFactory with our direct implementation
        $app->singleton(\Laravel\Passport\PersonalAccessTokenFactory::class, function ($app) {
            fwrite(STDERR, "[ddless] Creating DirectPersonalAccessTokenFactory...\n");
            return new DirectPersonalAccessTokenFactory(
                $app->make(\Laravel\Passport\ClientRepository::class)
            );
        });
        
        fwrite(STDERR, "[ddless] Passport PersonalAccessTokenFactory replaced successfully.\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "[ddless] Failed to replace PersonalAccessTokenFactory: " . $e->getMessage() . "\n");
        fwrite(STDERR, "[ddless] Stack: " . $e->getTraceAsString() . "\n");
    }
}

/**
 * Direct token factory that doesn't make HTTP requests
 * 
 * This replaces Laravel\Passport\PersonalAccessTokenFactory
 */
class DirectPersonalAccessTokenFactory
{
    protected $clients;

    public function __construct(\Laravel\Passport\ClientRepository $clients)
    {
        $this->clients = $clients;
    }

    /**
     * Create a new personal access token.
     *
     * @param  mixed  $userId
     * @param  string  $name
     * @param  array  $scopes
     * @return \Laravel\Passport\PersonalAccessTokenResult
     */
    public function make($userId, $name, array $scopes = [])
    {
        fwrite(STDERR, "[ddless] DirectPersonalAccessTokenFactory::make() for user {$userId}, name: {$name}\n");

        $client = $this->clients->personalAccessClient();

        if (!$client) {
            throw new \RuntimeException(
                '[ddless] Personal access client not found. Run: php artisan passport:client --personal'
            );
        }

        // Generate token ID
        $tokenId = hash('sha256', bin2hex(random_bytes(40)));

        // Calculate expiration
        $expiresAt = now()->addYear();
        if (class_exists('\Laravel\Passport\Passport')) {
            $expiration = \Laravel\Passport\Passport::personalAccessTokensExpireIn();
            if ($expiration) {
                $expiresAt = now()->add($expiration);
            }
        }

        // Create the token record directly using Eloquent
        $tokenModel = config('passport.token_model', \Laravel\Passport\Token::class);
        $token = new $tokenModel();
        $token->id = $tokenId;
        $token->user_id = $userId;
        $token->client_id = $client->getKey();
        $token->name = $name;
        $token->scopes = $scopes;
        $token->revoked = false;
        $token->created_at = now();
        $token->updated_at = now();
        $token->expires_at = $expiresAt;
        $token->save();

        // Generate the JWT access token
        $accessToken = $this->generateAccessToken($token, $client, $userId, $scopes, $expiresAt);

        fwrite(STDERR, "[ddless] Token created successfully with ID: " . substr($tokenId, 0, 8) . "...\n");

        return new \Laravel\Passport\PersonalAccessTokenResult(
            $accessToken,
            $token
        );
    }

    /**
     * Generate a JWT access token string
     */
    protected function generateAccessToken($token, $client, $userId, array $scopes, $expiresAt): string
    {
        // Try to use Lcobucci JWT library (Passport's default)
        if (class_exists('\Lcobucci\JWT\Configuration')) {
            return $this->generateJwtToken($token, $client, $userId, $scopes, $expiresAt);
        }

        // Fallback: generate a simple bearer token
        fwrite(STDERR, "[ddless] JWT library not found, using simple token\n");
        return $this->generateSimpleToken($token);
    }

    /**
     * Generate JWT token using Lcobucci library
     */
    protected function generateJwtToken($token, $client, $userId, array $scopes, $expiresAt): string
    {
        try {
            $privateKeyPath = \Laravel\Passport\Passport::keyPath('oauth-private.key');
            
            if (!file_exists($privateKeyPath)) {
                fwrite(STDERR, "[ddless] WARNING: Private key not found at {$privateKeyPath}\n");
                fwrite(STDERR, "[ddless] Run: php artisan passport:keys\n");
                return $this->generateSimpleToken($token);
            }

            $privateKey = file_get_contents($privateKeyPath);
            
            return $this->generateJwtV4($token, $client, $userId, $scopes, $expiresAt, $privateKey);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[ddless] JWT generation failed: " . $e->getMessage() . "\n");
            return $this->generateSimpleToken($token);
        }
    }

    /**
     * Generate JWT using Lcobucci 4.x/5.x
     */
    protected function generateJwtV4($token, $client, $userId, array $scopes, $expiresAt, string $privateKey): string
    {
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($privateKey),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText('') // Public key not needed for signing
        );

        $now = new \DateTimeImmutable();
        $expiresAtImmutable = $expiresAt instanceof \DateTimeImmutable 
            ? $expiresAt 
            : \DateTimeImmutable::createFromMutable(
                $expiresAt instanceof \DateTime ? $expiresAt : new \DateTime($expiresAt->format('Y-m-d H:i:s'))
            );

        $jwtToken = $config->builder()
            ->issuedBy(config('app.url', 'http://localhost'))
            ->permittedFor((string) $client->getKey())
            ->identifiedBy($token->id)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAtImmutable)
            ->relatedTo((string) $userId)
            ->withClaim('scopes', $scopes)
            ->getToken($config->signer(), $config->signingKey());

        return $jwtToken->toString();
    }

    /**
     * Generate a simple token (fallback when JWT is not available)
     */
    protected function generateSimpleToken($token): string
    {
        // Create a simple encrypted token that can be used as bearer
        // This is not as secure as JWT but works for debugging purposes
        $payload = json_encode([
            'token_id' => $token->id,
            'client_id' => $token->client_id,
            'user_id' => $token->user_id,
            'scopes' => $token->scopes,
            'expires_at' => $token->expires_at->timestamp ?? time() + 31536000,
            'random' => bin2hex(random_bytes(16)),
        ]);

        // Use Laravel's encryption if available
        if (function_exists('encrypt')) {
            try {
                return encrypt($payload);
            } catch (\Throwable $e) {
                // Fall through to base64
            }
        }

        return base64_encode($payload);
    }
}

/**
 * Not used - kept for compatibility
 */
function registerPassportMiddleware(Application $app): void
{
    // Not needed with factory replacement approach
}
