<?php

declare(strict_types=1);

namespace DbTools\Config;

final class ProfilesConfig
{
    /**
     * @param array<string,Profile> $profiles
     */
    public function __construct(
        private readonly array $profiles,
        private readonly ?string $defaultProfile
    ) {
    }

    /**
     * @return array<string,Profile>
     */
    public function profiles(): array
    {
        return $this->profiles;
    }

    public function defaultProfile(): ?string
    {
        return $this->defaultProfile;
    }

    public function getProfile(?string $name): ?Profile
    {
        if ($name !== null && isset($this->profiles[$name])) {
            return $this->profiles[$name];
        }

        if ($this->defaultProfile !== null && isset($this->profiles[$this->defaultProfile])) {
            return $this->profiles[$this->defaultProfile];
        }

        // fall back to first profile if available
        if ($this->profiles !== []) {
            return $this->profiles[array_key_first($this->profiles)];
        }

        return null;
    }
}
