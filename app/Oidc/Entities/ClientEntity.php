<?php

namespace App\Oidc\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait, EntityTrait;

    public function __construct(string $identifier, string $name, array $redirectUris, bool $isConfidential)
    {
        $this->setIdentifier($identifier);
        $this->name = $name;
        $this->redirectUri = $redirectUris;
        $this->isConfidential = $isConfidential;
    }
}
