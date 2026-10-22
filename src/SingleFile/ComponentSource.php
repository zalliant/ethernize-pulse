<?php

declare(strict_types=1);

namespace Ethernize\Pulse\SingleFile;

use InvalidArgumentException;
use ParseError;

/**
 * A Pulse single-file component: one class and one Weave template in the same file.
 *
 *   <?php
 *
 *   declare(strict_types=1);
 *
 *   namespace App\Pulse;
 *
 *   use Ethernize\Pulse\Attribute\Action;
 *   use Ethernize\Pulse\Component;
 *
 *   final class Counter extends Component
 *   {
 *       ...
 *   }
 *   ?>
 *   <button pulse:click="increment">{{ count }}</button>
 *
 * The file is a valid PHP file: the class is ordinary PHP, and the template is the text after the closing
 * tag (PHP treats it as inline HTML, which the loader discards). The file is read here with the PHP
 * tokenizer and never executed, so finding and compiling components cannot run code. Only these may appear
 * outside the class: declare(strict_types=1), one namespace, use imports, attributes, comments, and the
 * closing tag. The class must be named like the file (Products.pulse.php declares Products).
 */
final class ComponentSource
{
    public const SUFFIX = '.pulse.php';

    private function __construct(
        public readonly string $namespace,
        public readonly string $shortName,
        public readonly string $template,
        public readonly int $templateLineOffset,
    ) {
    }

    public function class(): string
    {
        return ($this->namespace === '' ? '' : $this->namespace . '\\') . $this->shortName;
    }

    /**
     * The kebab-case component name for a class name: ProductForm becomes product-form.
     *
     * @throws InvalidArgumentException
     */
    public static function nameFor(string $shortName): string
    {
        if (preg_match('/^[A-Z][a-z0-9]*(?:[A-Z][a-z0-9]*)*$/', $shortName) !== 1 || preg_match('/[A-Z]{2}/', $shortName) === 1) {
            throw new InvalidArgumentException(sprintf('"%s" cannot name a component. Use PascalCase without consecutive capitals, for example ProductForm or HtmlView.', $shortName));
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));
    }

    /**
     * @param string $path used in messages and to check that the class has the file's name
     * @throws InvalidArgumentException when the file is not a well-formed single-file component
     */
    public static function parse(string $contents, string $path): self
    {
        $fail = static fn (string $message, int $line = 1): never => throw new InvalidArgumentException(sprintf('%s:%d: %s', $path, $line, $message));

        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError $error) {
            $fail($error->getMessage(), $error->getLine());
        }

        $namespace = '';
        $class = null;
        $state = 'head';
        $depth = 0;
        $offset = 0;
        $templateStart = null;
        $declared = false;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            [$id, $text, $line] = is_array($token) ? $token : [null, $token, 0];
            $line = $line > 0 ? $line : (int) (substr_count(substr($contents, 0, $offset), "\n") + 1);
            $offset += strlen($text);

            if ($state === 'template') {
                if ($id !== T_INLINE_HTML) {
                    $fail('PHP tags are not allowed in the template. Use Weave expressions: {{ value }}, @if, @each.', $line);
                }

                continue;
            }

            if ($state === 'head') {
                if ($i === 0) {
                    if ($id !== T_OPEN_TAG) {
                        $fail('A component file starts with <?php.', $line);
                    }

                    continue;
                }

                switch ($id) {
                    case T_WHITESPACE:
                    case T_COMMENT:
                    case T_DOC_COMMENT:
                    case T_FINAL:
                    case T_READONLY:
                        break;

                    case T_DECLARE:
                        $statement = self::statement($tokens, $i, $offset, $contents);

                        if (preg_replace('/\s+/', '', $statement['text']) !== 'declare(strict_types=1);') {
                            $fail('Only declare(strict_types=1); is allowed before the class.', $line);
                        }

                        break;

                    case T_NAMESPACE:
                        $statement = self::statement($tokens, $i, $offset, $contents, stopAtBrace: true);

                        if ($declared || !str_ends_with($statement['text'], ';')) {
                            $fail('Use one namespace statement ending in ";" (no braces).', $line);
                        }

                        $namespace = trim(substr($statement['text'], strlen('namespace'), -1));
                        $declared = true;
                        break;

                    case T_CLOSE_TAG:
                        $fail('The file declares no class.', $line);

                    case T_USE:
                        self::statement($tokens, $i, $offset, $contents);
                        break;

                    case T_ATTRIBUTE:
                        $bracket = 1;

                        while ($bracket > 0 && ++$i < $count) {
                            $inner = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                            $offset += strlen($inner);
                            $bracket += $inner === '[' ? 1 : ($inner === ']' ? -1 : 0);
                        }

                        break;

                    case T_CLASS:
                        while (++$i < $count && ($tokens[$i][0] ?? null) === T_WHITESPACE) {
                            $offset += strlen($tokens[$i][1]);
                        }

                        if (($tokens[$i][0] ?? null) !== T_STRING) {
                            $fail('Expected the class name.', $line);
                        }

                        $class = $tokens[$i][1];
                        $offset += strlen($class);

                        while (++$i < $count) {
                            $inner = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                            $offset += strlen($inner);

                            if ($inner === '{') {
                                $depth = 1;
                                $state = 'body';
                                break;
                            }
                        }

                        break;

                    default:
                        $fail(sprintf('Only declare(strict_types=1), one namespace, use imports and the component class may appear in the file (found "%s"). Put code inside the class.', trim($text) !== '' ? trim($text) : token_name((int) $id)), $line);
                }

                continue;
            }

            if ($state === 'body') {
                $depth += match (true) {
                    $text === '{', $id === T_CURLY_OPEN, $id === T_DOLLAR_OPEN_CURLY_BRACES => 1,
                    $text === '}' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $state = 'after';
                }

                continue;
            }

            // After the class: only trivia, then the closing tag that starts the template.
            switch ($id) {
                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    break;

                case T_CLOSE_TAG:
                    $templateStart = $offset;
                    $state = 'template';
                    break;

                default:
                    $fail('Only one class per file. After the class, close PHP with ?> and write the template.', $line);
            }
        }

        if ($class === null) {
            $fail('The file declares no class.');
        }

        if ($state !== 'template' || $templateStart === null) {
            $fail('The template is missing: close PHP with ?> after the class and write the Weave template below it.', substr_count($contents, "\n") + 1);
        }

        $expected = basename($path);

        if (!str_ends_with($expected, self::SUFFIX) || substr($expected, 0, -strlen(self::SUFFIX)) !== $class) {
            $fail(sprintf('The class %s must be in a file named %s%s.', $class, $class, self::SUFFIX));
        }

        return new self($namespace, $class, substr($contents, $templateStart), substr_count(substr($contents, 0, $templateStart), "\n"));
    }

    /**
     * Reads one statement up to its ";" and returns its text. $i and $offset move to the ";".
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{text: string}
     */
    private static function statement(array $tokens, int &$i, int &$offset, string $contents, bool $stopAtBrace = false): array
    {
        $start = $offset - strlen(is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);
        $count = count($tokens);

        while (++$i < $count) {
            $inner = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $offset += strlen($inner);

            if ($inner === ';' || ($stopAtBrace && $inner === '{')) {
                break;
            }
        }

        return ['text' => substr($contents, $start, $offset - $start)];
    }
}
