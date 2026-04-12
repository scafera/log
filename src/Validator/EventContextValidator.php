<?php

declare(strict_types=1);

namespace Scafera\Log\Validator;

use Scafera\Kernel\Contract\ValidatorInterface;

/**
 * Validates that every logger call in src/ includes a lowercase dot-notation 'event' key.
 *
 * Uses PHP's tokenizer (`\PhpToken::tokenize`) to reliably detect logger calls,
 * including calls that span multiple lines.
 *
 * Known limitation — variable context:
 * Only inline array literals at the call site can be inspected. Calls that pass a
 * variable as the context argument (e.g. `$this->logger->info($msg, $context)`)
 * are silently skipped, because the validator cannot see what `$context` contains
 * without type inference. Teams that want full coverage should use inline arrays
 * at logger call sites.
 */
final class EventContextValidator implements ValidatorInterface
{
    private const EVENT_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/';

    private const LOGGER_METHODS = [
        'debug' => true,
        'info' => true,
        'notice' => true,
        'warning' => true,
        'error' => true,
        'critical' => true,
        'alert' => true,
        'emergency' => true,
    ];

    public function getName(): string
    {
        return 'EventContextValidator';
    }

    public function validate(string $projectDir): array
    {
        $srcDir = $projectDir . '/src';

        if (!is_dir($srcDir)) {
            return [];
        }

        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $fileViolations = $this->validateFile($file->getPathname(), $projectDir);
            $violations = [...$violations, ...$fileViolations];
        }

        return $violations;
    }

    /** @return list<string> */
    private function validateFile(string $filePath, string $projectDir): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return [];
        }

        if (!str_contains($content, 'LoggerInterface')) {
            return [];
        }

        $tokens = \PhpToken::tokenize($content);
        $count = count($tokens);

        $relativePath = str_starts_with($filePath, $projectDir)
            ? ltrim(substr($filePath, strlen($projectDir)), '/')
            : $filePath;

        $violations = [];

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_OBJECT_OPERATOR)) {
                continue;
            }

            $methodIndex = $this->nextMeaningful($tokens, $i + 1, $count);
            if ($methodIndex === null || !$tokens[$methodIndex]->is(T_STRING)) {
                continue;
            }

            $method = $tokens[$methodIndex]->text;
            if (!isset(self::LOGGER_METHODS[$method])) {
                continue;
            }

            $parenIndex = $this->nextMeaningful($tokens, $methodIndex + 1, $count);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }

            $line = $tokens[$methodIndex]->line;
            $arguments = $this->parseArguments($tokens, $parenIndex, $count);

            $violation = $this->checkCall($arguments, $tokens, $relativePath, $line);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Split the argument list of a call into top-level argument token ranges.
     *
     * @param list<\PhpToken> $tokens
     * @return list<array{0: int, 1: int}> Inclusive [start, end] index pairs per argument.
     */
    private function parseArguments(array $tokens, int $openParen, int $count): array
    {
        $depth = 0;
        $argStart = $openParen + 1;
        $args = [];

        for ($i = $openParen; $i < $count; $i++) {
            $t = $tokens[$i]->text;

            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
                continue;
            }

            if ($t === ')' || $t === ']' || $t === '}') {
                $depth--;

                if ($depth === 0 && $t === ')') {
                    if ($argStart <= $i - 1) {
                        $args[] = [$argStart, $i - 1];
                    }
                    return $args;
                }

                continue;
            }

            if ($depth === 1 && $t === ',') {
                $args[] = [$argStart, $i - 1];
                $argStart = $i + 1;
            }
        }

        return [];
    }

    /**
     * @param list<array{0: int, 1: int}> $arguments
     * @param list<\PhpToken>              $tokens
     */
    private function checkCall(array $arguments, array $tokens, string $path, int $line): ?string
    {
        if (count($arguments) < 2) {
            return sprintf('%s:%d — logger call missing \'event\' key in context', $path, $line);
        }

        [$start, $end] = $arguments[1];

        $firstIndex = $this->nextMeaningful($tokens, $start, $end + 1);
        if ($firstIndex === null) {
            return null;
        }

        $first = $tokens[$firstIndex];
        $isInlineArray = $first->text === '[' || $first->is(T_ARRAY);

        if (!$isInlineArray) {
            // Variable context — documented limitation, silently skip.
            return null;
        }

        $result = $this->findEventEntry($tokens, $firstIndex, $end);

        if (!$result['present']) {
            return sprintf('%s:%d — logger call missing \'event\' key in context', $path, $line);
        }

        if ($result['value'] === null) {
            // 'event' key is present but its value is dynamic (variable, constant, etc.)
            // — format check is not possible, skip.
            return null;
        }

        if (!preg_match(self::EVENT_PATTERN, $result['value'])) {
            return sprintf(
                '%s:%d — event \'%s\' does not match required format (lowercase dot notation)',
                $path,
                $line,
                $result['value'],
            );
        }

        return null;
    }

    /**
     * Scan the top level of an inline array literal for an 'event' key.
     * Ignores nested arrays/expressions.
     *
     * @param  list<\PhpToken>                       $tokens
     * @return array{present: bool, value: ?string} value=null means present-but-dynamic.
     */
    private function findEventEntry(array $tokens, int $start, int $end): array
    {
        $open = $start;

        if ($tokens[$open]->is(T_ARRAY)) {
            $next = $this->nextMeaningful($tokens, $open + 1, $end + 1);
            if ($next === null || $tokens[$next]->text !== '(') {
                return ['present' => false, 'value' => null];
            }
            $open = $next;
        } elseif ($tokens[$open]->text !== '[') {
            return ['present' => false, 'value' => null];
        }

        $depth = 0;

        for ($i = $open; $i <= $end; $i++) {
            $t = $tokens[$i]->text;

            if ($t === '[' || $t === '(' || $t === '{') {
                $depth++;
                continue;
            }

            if ($t === ']' || $t === ')' || $t === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if (!$tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }

            $keyText = $tokens[$i]->text;
            if ($keyText !== "'event'" && $keyText !== '"event"') {
                continue;
            }

            $arrowIndex = $this->nextMeaningful($tokens, $i + 1, $end + 1);
            if ($arrowIndex === null || !$tokens[$arrowIndex]->is(T_DOUBLE_ARROW)) {
                continue;
            }

            $valueIndex = $this->nextMeaningful($tokens, $arrowIndex + 1, $end + 1);
            if ($valueIndex === null || !$tokens[$valueIndex]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return ['present' => true, 'value' => null];
            }

            $value = $tokens[$valueIndex]->text;
            return ['present' => true, 'value' => substr($value, 1, -1)];
        }

        return ['present' => false, 'value' => null];
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function nextMeaningful(array $tokens, int $from, int $limit): ?int
    {
        for ($i = $from; $i < $limit; $i++) {
            if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            return $i;
        }

        return null;
    }
}
