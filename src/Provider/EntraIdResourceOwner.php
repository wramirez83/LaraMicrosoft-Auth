<?php

declare(strict_types=1);

namespace LaraMicrosoft\Auth\Provider;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

final class EntraIdResourceOwner implements ResourceOwnerInterface
{
    public function __construct(
        private array $response,
    ) {
    }

    public function getId(): ?string
    {
        return $this->response['sub'] ?? $this->response['oid'] ?? null;
    }

    public function getEmail(): ?string
    {
        return $this->response['email'] ?? $this->response['preferred_username'] ?? null;
    }

    public function getName(): ?string
    {
        return $this->response['name'] ?? null;
    }

    public function getGivenName(): ?string
    {
        return $this->response['given_name'] ?? null;
    }

    public function getFamilyName(): ?string
    {
        return $this->response['family_name'] ?? null;
    }

    public function toArray(): array
    {
        return $this->response;
    }
}
