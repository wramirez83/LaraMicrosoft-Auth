<?php

declare(strict_types=1);

namespace LaraMicrosoft\Auth\Tests;

use LaraMicrosoft\Auth\Config\EntraIdConfig;
use LaraMicrosoft\Auth\EntraIdAuthService;
use PHPUnit\Framework\TestCase;

final class EntraIdAuthServiceTest extends TestCase
{
    public function test_get_authorization_url_returns_url_and_state(): void
    {
        $config = new EntraIdConfig(
            clientId: 'test-client',
            clientSecret: 'test-secret',
            redirectUri: 'https://app.test/callback',
        );
        $service = new EntraIdAuthService($config);

        $result = $service->getAuthorizationUrl();

        self::assertArrayHasKey('url', $result);
        self::assertArrayHasKey('state', $result);
        self::assertStringContainsString('login.microsoftonline.com', $result['url']);
        self::assertStringContainsString('client_id=test-client', $result['url']);
        self::assertSame(32, \strlen($result['state']));
    }

    public function test_get_authorization_url_uses_provided_state(): void
    {
        $config = new EntraIdConfig(
            clientId: 'test-client',
            clientSecret: 'test-secret',
            redirectUri: 'https://app.test/callback',
        );
        $service = new EntraIdAuthService($config);
        $state = 'my-custom-state-123';

        $result = $service->getAuthorizationUrl($state);

        self::assertSame($state, $result['state']);
    }

    public function test_config_from_array(): void
    {
        $config = EntraIdConfig::fromArray([
            'client_id'     => 'cid',
            'client_secret' => 'secret',
            'redirect_uri'  => 'https://example.com/cb',
            'tenant'        => 'organizations',
        ]);

        self::assertSame('cid', $config->clientId);
        self::assertSame('secret', $config->clientSecret);
        self::assertSame('organizations', $config->tenant);
    }
}
