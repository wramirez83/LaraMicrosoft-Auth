<?php

declare(strict_types=1);

namespace LaraMicrosoft\Auth;

use LaraMicrosoft\Auth\Config\EntraIdConfig;
use LaraMicrosoft\Auth\Exception\InvalidStateException;
use LaraMicrosoft\Auth\Exception\TokenExchangeException;
use LaraMicrosoft\Auth\Provider\EntraIdProvider;
use LaraMicrosoft\Auth\Provider\EntraIdResourceOwner;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Servicio de autenticación con Microsoft Entra ID (Office 365).
 *
 * Uso típico desde backend (PHP):
 * 1. Frontend pide la URL de login → getAuthorizationUrl($state)
 * 2. Usuario es redirigido a Microsoft y vuelve al callback con ?code=...&state=...
 * 3. Backend recibe code + state → exchangeCodeForToken($code, $state) y opcionalmente getUser($accessToken)
 *
 * El frontend (Vue/Nuxt/React) solo redirige a la URL y luego envía code + state al backend.
 */
final class EntraIdAuthService
{
    private EntraIdProvider $provider;

    public function __construct(EntraIdConfig $config)
    {
        $this->provider = new EntraIdProvider($config);
    }

    /**
     * Genera la URL a la que el frontend debe redirigir al usuario para iniciar sesión con Entra ID.
     *
     * @param string|null $state Valor para CSRF; si es null se genera uno aleatorio. Debe guardarse (ej. sesión) y validarse en el callback.
     * @return array{url: string, state: string}
     */
    public function getAuthorizationUrl(?string $state = null): array
    {
        $state = $state ?? $this->generateState();
        $url = $this->provider->getAuthorizationUrl([
            'state' => $state,
        ]);
        return [
            'url'   => $url,
            'state' => $state,
        ];
    }

    /**
     * Intercambia el código de autorización por tokens y opcionalmente obtiene el usuario.
     *
     * @param string $code  Código recibido en el callback (query param "code")
     * @param string $state Estado recibido en el callback; debe coincidir con el guardado al generar la URL
     * @param string|null $expectedState Estado esperado (el que guardaste en sesión). Si es null no se valida (no recomendado).
     * @throws InvalidStateException Si $state !== $expectedState
     * @throws TokenExchangeException Si Entra ID devuelve error al canjear el código
     */
    public function exchangeCodeForToken(string $code, string $state, ?string $expectedState = null): AccessToken
    {
        if ($expectedState !== null && !hash_equals($expectedState, $state)) {
            throw new InvalidStateException('Invalid state parameter; possible CSRF.');
        }

        try {
            return $this->provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);
        } catch (IdentityProviderException $e) {
            throw new TokenExchangeException(
                'Token exchange failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Obtiene el recurso de usuario desde Microsoft Graph (OIDC userinfo) usando el access token.
     */
    public function getUser(AccessToken $accessToken): EntraIdResourceOwner
    {
        $owner = $this->provider->getResourceOwner($accessToken);
        if (!$owner instanceof EntraIdResourceOwner) {
            throw new TokenExchangeException('Unexpected resource owner type.');
        }
        return $owner;
    }

    /**
     * Flujo completo: canjea el código y devuelve el access token y el usuario en un solo paso.
     *
     * @return array{token: AccessToken, user: EntraIdResourceOwner}
     */
    public function exchangeCodeAndGetUser(string $code, string $state, ?string $expectedState = null): array
    {
        $token = $this->exchangeCodeForToken($code, $state, $expectedState);
        $user = $this->getUser($token);
        return [
            'token' => $token,
            'user'  => $user,
        ];
    }

    public function getConfig(): EntraIdConfig
    {
        return $this->provider->getConfig();
    }

    private function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }
}
