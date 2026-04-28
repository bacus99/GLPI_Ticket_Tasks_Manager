<?php

namespace GlpiPlugin\Tasksmanager\Form\Destination;

use Glpi\DBAL\JsonFieldInterface;

final class WorkflowFieldConfig implements JsonFieldInterface
{
    public function __construct(private readonly string $value = '')
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public static function jsonDeserialize(array $data): static
    {
        return new self($data['value'] ?? '');
    }

    public function jsonSerialize(): array
    {
        return ['value' => $this->value];
    }
}
