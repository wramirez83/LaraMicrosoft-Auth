<?php

declare(strict_types=1);

namespace LaraMicrosoft\Auth\Provider;

use LaraMicrosoft\Auth\Config\EntraIdConfig;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

final class EntraIdProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    public function __construct(
        private EntraIdConfig $config,
        array $collaborators = [],
    ) {
        parent::__construct([
            'clientId'     => $config->clientId,
            'clientSecret' => $config->clientSecret,
            'redirectUri'  => $config->redirectUri,
        ], array_merge([
            'optionProvider' => new PostAuthOptionProvider(),
        ], $collaborators));
    }

    public function getBaseAuthorizationUrl(): string
    {
        return $this->config->getBaseUrl() . '/authorize';
    }

    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->config->getBaseUrl() . '/token';
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return 'https://graph.microsoft.com/oidc/userinfo';
    }

    protected function getDefaultScopes(): array
    {
        return $this->config->scopes;
    }

    protected function getAuthorizationParameters(array $options): array
    {
        $params = parent::getAuthorizationParameters($options);
        $params['response_mode'] = $options['response_mode'] ?? 'query';
        if ($this->config->prompt !== null) {
            $params['prompt'] = $this->config->prompt;
        }
        return $params;
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() >= 400) {
            $error = $data['error'] ?? 'unknown_error';
            $description = $data['error_description'] ?? $response->getReasonPhrase();
            throw new IdentityProviderException($description, $response->getStatusCode(), (array) $data);
        }
    }

    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        return new EntraIdResourceOwner($response);
    }

    public function getConfig(): EntraIdConfig
    {
        return $this->config;
    }
}
