<?php

declare(strict_types=1);

namespace Divi5Validator;

final class ValidationResult
{
    /** @param Violation[] $violations */
    public function __construct(
        private readonly array $violations = [],
    ) {}

    public function isValid(): bool { return $this->violations === []; }

    /** @return Violation[] */
    public function violations(): array { return $this->violations; }

    public function toArray(): array
    {
        return [
            'valid'      => $this->isValid(),
            'violations' => array_map(fn(Violation $v) => $v->toArray(), $this->violations),
        ];
    }
}
