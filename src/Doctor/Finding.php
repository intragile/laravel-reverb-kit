<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Doctor;

/**
 * One result of a doctor rule: what was found and, when something is off, how to fix it.
 */
final readonly class Finding
{
    public function __construct(
        public string $rule,
        public Status $status,
        public string $message,
        public ?string $hint = null,
    ) {}

    public static function ok(string $rule, string $message, ?string $hint = null): self
    {
        return new self($rule, Status::Ok, $message, $hint);
    }

    public static function skipped(string $rule, string $message, ?string $hint = null): self
    {
        return new self($rule, Status::Skipped, $message, $hint);
    }

    public static function warning(string $rule, string $message, ?string $hint = null): self
    {
        return new self($rule, Status::Warning, $message, $hint);
    }

    public static function failed(string $rule, string $message, ?string $hint = null): self
    {
        return new self($rule, Status::Failed, $message, $hint);
    }
}
