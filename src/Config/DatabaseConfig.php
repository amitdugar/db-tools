<?php

declare(strict_types=1);

namespace DbTools\Config;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $host,
        public readonly string $database,
        public readonly ?string $user = null,
        public readonly ?string $password = null,
        public readonly ?int $port = null,
    ) {
    }

    /**
     * @return array{host:string,database:string,user:?string,password:?string,port:?int}
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'database' => $this->database,
            'user' => $this->user,
            'password' => $this->password,
            'port' => $this->port,
        ];
    }
}
