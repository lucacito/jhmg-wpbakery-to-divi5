<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * Parses WordPress Gutenberg block HTML into a tree of Block objects.
 *
 * Handles three syntactic forms:
 *   Opening:      <!-- wp:NAME {JSON} -->
 *   Self-closing: <!-- wp:NAME {JSON} /-->
 *   Closing:      <!-- /wp:NAME -->
 */
final class BlockParser
{
    /**
     * Parse a Gutenberg block HTML string into a flat list of tokens,
     * then build a tree.
     *
     * @return ParseResult
     */
    public function parse(string $html): ParseResult
    {
        $errors = [];
        $tokens = $this->tokenize($html, $errors);

        if ($errors !== []) {
            return new ParseResult(null, $errors);
        }

        [$tree, $buildErrors] = $this->buildTree($tokens);
        return new ParseResult($tree, $buildErrors);
    }

    // ---------------------------------------------------------------
    // Tokenizer
    // ---------------------------------------------------------------

    /**
     * @param  string[]  $errors   collected by reference
     * @return array<array{type:string, name:string, attrs:array<string,mixed>}>
     */
    private function tokenize(string $html, array &$errors): array
    {
        $tokens = [];

        // Self-closing: <!-- wp:name {...} /-->
        // Opening:      <!-- wp:name {...} -->
        // Closing:      <!-- /wp:name -->
        $pattern = '/<!--\s+(\/wp:[a-z0-9\/_-]+|wp:[a-z0-9\/_-]+(?:\s+\{[^}]*(?:\{[^}]*\}[^}]*)?\})?)\s+(\/?)-->/';

        // Simpler, more robust approach: match the three forms separately
        $tokens = [];
        $offset = 0;
        $len    = strlen($html);

        while ($offset < $len) {
            $start = strpos($html, '<!--', $offset);
            if ($start === false) {
                break;
            }
            $end = strpos($html, '-->', $start);
            if ($end === false) {
                $errors[] = 'Unclosed HTML comment starting at offset ' . $start;
                break;
            }

            $comment = substr($html, $start + 4, $end - $start - 4); // strip <!-- and -->
            $comment = trim($comment);

            // Closing block: /wp:name
            if (preg_match('/^\/wp:([a-z0-9\/_-]+)$/i', $comment, $m)) {
                $tokens[] = ['type' => 'close', 'name' => $m[1], 'attrs' => []];
            }
            // Self-closing or opening: wp:name {json} or wp:name {json} /
            elseif (preg_match('/^wp:([a-z0-9\/_-]+)(\s+(\{.*?\}))?\s*(\/?)$/is', $comment, $m)) {
                $name      = $m[1];
                $jsonStr   = isset($m[3]) ? trim($m[3]) : '{}';
                $selfClose = isset($m[4]) && $m[4] === '/';

                $attrs = [];
                if ($jsonStr !== '' && $jsonStr !== '{}') {
                    $attrs = json_decode($jsonStr, associative: true);
                    if ($attrs === null && json_last_error() !== JSON_ERROR_NONE) {
                        $errors[] = "Invalid JSON in block wp:$name at offset $start: " . json_last_error_msg();
                        $attrs = [];
                    }
                }

                $tokens[] = [
                    'type'  => $selfClose ? 'self-close' : 'open',
                    'name'  => $name,
                    'attrs' => $attrs ?? [],
                ];
            }

            $offset = $end + 3; // skip past -->
        }

        return $tokens;
    }

    // ---------------------------------------------------------------
    // Tree builder
    // ---------------------------------------------------------------

    /**
     * @param  array<array{type:string,name:string,attrs:array}> $tokens
     * @return array{?Block, string[]}
     */
    private function buildTree(array $tokens): array
    {
        $errors = [];
        $root   = new Block('__root__', []);
        $stack  = [$root];

        foreach ($tokens as $token) {
            $current = end($stack);

            if ($token['type'] === 'self-close') {
                $block = new Block($token['name'], $token['attrs']);
                $current->addChild($block);
            } elseif ($token['type'] === 'open') {
                $block = new Block($token['name'], $token['attrs']);
                $current->addChild($block);
                $stack[] = $block;
            } elseif ($token['type'] === 'close') {
                if (count($stack) <= 1) {
                    $errors[] = "Unexpected closing tag </wp:{$token['name']}> with no matching opening tag.";
                    continue;
                }
                $top = array_pop($stack);
                if ($top->name() !== $token['name']) {
                    $errors[] = "Mismatched block tags: opened <wp:{$top->name()}> but closed </wp:{$token['name']}>.";
                    // push it back and continue; we'll report errors, not crash
                    $stack[] = $top;
                }
            }
        }

        if (count($stack) > 1) {
            $unclosed = array_slice($stack, 1);
            foreach ($unclosed as $b) {
                $errors[] = "Unclosed block <wp:{$b->name()}>.";
            }
        }

        return [$root, $errors];
    }
}
