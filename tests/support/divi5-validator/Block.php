<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * A single parsed Gutenberg block node in the layout tree.
 */
final class Block
{
    /** @var Block[] */
    private array $children = [];

    /** @param array<string, mixed> $attrs */
    public function __construct(
        private readonly string $blockName,
        private readonly array  $attrs,
    ) {}

    public function name(): string { return $this->blockName; }

    /** @return array<string, mixed> */
    public function attrs(): array { return $this->attrs; }

    /** @return Block[] */
    public function children(): array { return $this->children; }

    public function addChild(Block $child): void
    {
        $this->children[] = $child;
    }

    /**
     * Get a deeply nested attribute value via dot-path.
     * Returns null if any segment is missing.
     */
    public function attr(string $dotPath): mixed
    {
        $parts = explode('.', $dotPath);
        $node  = $this->attrs;
        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Walk this node and all descendants depth-first. */
    public function walk(callable $callback, string $path = ''): void
    {
        $myPath = $path === '' ? $this->blockName : $path . '/' . $this->blockName;
        $callback($this, $myPath);
        foreach ($this->children as $i => $child) {
            $child->walk($callback, $myPath . '[' . $i . ']');
        }
    }
}
