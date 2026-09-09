<?php

declare(strict_types=1);

namespace Divi5Validator;

final class ParseResult
{
    /** @param string[] $errors */
    public function __construct(
        private readonly ?Block $root,
        private readonly array  $errors = [],
    ) {}

    public function isOk(): bool    { return $this->errors === [] && $this->root !== null; }
    public function root(): ?Block  { return $this->root; }
    /** @return string[] */
    public function errors(): array { return $this->errors; }
}
