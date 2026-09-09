<?php

declare(strict_types=1);

namespace Divi5Validator;

final class Violation
{
    public function __construct(
        private readonly string $code,
        private readonly string $message,
        private readonly string $path = '',
    ) {}

    public function code(): string    { return $this->code; }
    public function message(): string { return $this->message; }
    public function path(): string    { return $this->path; }

    public function toArray(): array
    {
        return [
            'code'    => $this->code,
            'message' => $this->message,
            'path'    => $this->path,
        ];
    }
}
