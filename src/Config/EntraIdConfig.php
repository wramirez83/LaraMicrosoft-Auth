<?php

declare(strict_types=1);

namespace LaraMicrosoft\Auth\Config;

final readonly class EntraIdConfig
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public string $tenant = 'common',
        /** @var list<string> */
        public array $scopes = ['openid', 'profile', 'email', 'User.Read'],
        public ?string $prompt = null,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            clientId: $config['client_id'] ?? throw new \InvalidArgumentException('client_id is required'),
            clientSecret: $config['client_secret'] ?? throw new \InvalidArgumentException('client_secret is required'),
            redirectUri: $config['redirect_uri'] ?? throw new \InvalidArgumentException('redirect_uri is required'),
            tenant: $config['tenant'] ?? 'common',
            scopes: $config['scopes'] ?? ['openid', 'profile', 'email', 'User.Read'],
            prompt: $config['prompt'] ?? null,
        );
    }

    public function getBaseUrl(): string
    {
        return 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0';
    }
}
