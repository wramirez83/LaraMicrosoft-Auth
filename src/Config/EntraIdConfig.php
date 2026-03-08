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
        if ($clientId === '') {
            throw new \InvalidArgumentException('client_id is required and cannot be empty (Azure Application/Client ID).');
        }
        if ($clientSecret === '') {
            throw new \InvalidArgumentException('client_secret is required and cannot be empty.');
        }
        if ($redirectUri === '') {
            throw new \InvalidArgumentException('redirect_uri is required and cannot be empty.');
        }
    }

    public static function fromArray(array $config): self
    {
        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;
        $redirectUri = $config['redirect_uri'] ?? null;

        if ($clientId === null || $clientId === '') {
            throw new \InvalidArgumentException(
                'client_id is required and cannot be empty. Set the Azure Application (client) ID from your app registration.'
            );
        }
        if ($clientSecret === null || $clientSecret === '') {
            throw new \InvalidArgumentException('client_secret is required and cannot be empty.');
        }
        if ($redirectUri === null || $redirectUri === '') {
            throw new \InvalidArgumentException('redirect_uri is required and cannot be empty.');
        }

        return new self(
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: $redirectUri,
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
