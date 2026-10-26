<?php

declare(strict_types=1);

namespace Flexi\Application\Commands;

use Flexi\Contracts\Interfaces\DTOInterface;

final class PublishModuleCommand implements DTOInterface
{
    private string $moduleName;

    public function __construct(array $data = [])
    {
        $this->moduleName = (string) ($data['module_name'] ?? $data[0] ?? '');
    }

    public static function fromArray(array $data): DTOInterface
    {
        return new self($data);
    }

    public static function validate(array $data): bool
    {
        return trim((string) ($data['module_name'] ?? $data[0] ?? '')) !== '';
    }

    public function get(string $name)
    {
        return 'module_name' === $name ? $this->moduleName : null;
    }

    public function toArray(): array
    {
        return ['module_name' => $this->moduleName];
    }

    public function __toString(): string
    {
        return __CLASS__;
    }
}
