<?php

declare(strict_types=1);

namespace WpOnepixStandard\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\BackCompat\Helper;
use WpOnepixStandard\Helper\NamespacesTrait;

use function in_array;
use function sort;
use function sprintf;
use function strtolower;

use const T_DOUBLE_COLON;
use const T_FUNCTION;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_OBJECT_OPERATOR;
use const T_OPEN_PARENTHESIS;
use const T_STRING;
use const T_WHITESPACE;

final class ImportInternalFunctionSniff implements Sniff
{
    use NamespacesTrait;

    /**
     * @var string[] Array of functions to exclude from importing.
     */
    public array $exclude = [];

    /**
     * @var array Hash map of all php built in function names.
     */
    private array $builtInFunctions;

    /**
     * @var array<string, array{name: string, fqn: string, ptr: int}> $importedFunctions
     */
    private array $importedFunctions = [];

    public function __construct()
    {
        $this->builtInFunctions = $this->getBuiltInFunctions();
    }

    /**
     * @return int[]
     */
    #[\Override]
    public function register(): array
    {
        return [T_STRING];
    }

    private function setParameters(): void
    {
        $cliExclude = Helper::getConfigData('exclude') ?? [];
        $this->exclude = (array) $cliExclude;
    }

    /**
     * @param int $stackPtr
     * @return int
     */
    #[\Override]
    public function process(File $phpcsFile, $stackPtr)
    {
        $this->setParameters();
        /** @var array<int, array{code: int, content?: string, comment_closer?: int}> $tokens */
        $tokens = $phpcsFile->getTokens();

        /** @var int|null $currentNamespacePtr */
        $currentNamespacePtr = null;
        $functionsToImport = [];

        do {
            $foundPtr = $phpcsFile->findPrevious(T_NAMESPACE, $stackPtr - 1);
            $namespacePtr = $foundPtr !== false ? $foundPtr : null;

            if ($namespacePtr !== $currentNamespacePtr) {
                if (is_int($currentNamespacePtr)) {
                    $this->importFunctions($phpcsFile, $currentNamespacePtr, $functionsToImport);
                }

                $currentNamespacePtr = $namespacePtr;
                $functionsToImport = [];

                $this->importedFunctions = $this->getGlobalUses(
                    $phpcsFile,
                    is_int($namespacePtr) ? $namespacePtr : 0,
                    'function'
                );

                foreach ($this->importedFunctions as $func) {
                    $fqn = strtolower($func['fqn'] ?? '');

                    if (in_array($fqn, $this->exclude, true)) {
                        $error = 'Function %s cannot be imported';
                        $data = [$func['fqn']];
                        $fix = $phpcsFile->addFixableError($error, $func['ptr'], 'ExcludeImported', $data);

                        if ($fix) {
                            $eos = $phpcsFile->findEndOfStatement($func['ptr']);

                            $phpcsFile->fixer->beginChangeset();
                            for ($i = $func['ptr']; $i <= $eos; ++$i) {
                                $phpcsFile->fixer->replaceToken($i, '');
                            }
                            if ($tokens[$i + 1]['code'] === T_WHITESPACE) {
                                $phpcsFile->fixer->replaceToken($i + 1, '');
                            }
                            $phpcsFile->fixer->endChangeset();
                        }
                    }
                }
            }

            $functionName = $this->processString(
                $phpcsFile,
                $stackPtr,
                $namespacePtr !== null ? $namespacePtr : null
            );
            if ($functionName !== null) {
                $functionsToImport[] = $functionName;
            }
        } while ($stackPtr = $phpcsFile->findNext($this->register(), $stackPtr + 1));

        if (is_int($currentNamespacePtr)) {
            $this->importFunctions($phpcsFile, $currentNamespacePtr, $functionsToImport);
        }

        return $phpcsFile->numTokens + 1;
    }

    private function processString(File $phpcsFile, int $stackPtr, ?int $namespacePtr): ?string
    {
        /** @var array<int, array{code: int, content?: string, comment_closer?: int}> $tokens */
        $tokens = $phpcsFile->getTokens();

        // Make sure this is a function call.
        $next = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, null, true);
        if ($next === false || $tokens[$next]['code'] !== T_OPEN_PARENTHESIS) {
            return null;
        }

        $content = isset($tokens[$stackPtr]['content']) ? strtolower($tokens[$stackPtr]['content']) : '';
        if (! isset($this->builtInFunctions[$content])) {
            return null;
        }

        $prev = $phpcsFile->findPrevious(
            Tokens::$emptyTokens + [T_NS_SEPARATOR => T_NS_SEPARATOR],
            $stackPtr - 1,
            null,
            true
        );
        if (
            $tokens[$prev]['code'] === T_FUNCTION
            || $tokens[$prev]['code'] === T_NEW
            || $tokens[$prev]['code'] === T_STRING
            || $tokens[$prev]['code'] === T_DOUBLE_COLON
            || $tokens[$prev]['code'] === T_OBJECT_OPERATOR
        ) {
            return null;
        }

        $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, null, true);
        if ($prev !== false && $tokens[$prev]['code'] === T_NS_SEPARATOR) {
            if ($namespacePtr === null) {
                $error = 'FQN for PHP internal function "%s" is not needed here, file does not have defined namespace';
                $data = [
                    $content,
                ];

                $fix = $phpcsFile->addFixableError($error, $stackPtr, 'NoNamespace', $data);
                if ($fix) {
                    $phpcsFile->fixer->replaceToken($prev, '');
                }
            } elseif (in_array($content, $this->exclude, true)) {
                $error = 'FQN for PHP internal function "%s" is not allowed here';
                $data = [
                    $content,
                ];

                $fix = $phpcsFile->addFixableError($error, $stackPtr, 'ExcludeRedundantFQN', $data);
                if ($fix) {
                    $phpcsFile->fixer->replaceToken($prev, '');
                }
            } elseif (isset($this->importedFunctions[$content]['fqn'])) {
                if (strtolower($this->importedFunctions[$content]['fqn']) === $content) {
                    $error = 'FQN for PHP internal function "%s" is not needed here, function is already imported';
                    $data = [
                        $content,
                    ];

                    $fix = $phpcsFile->addFixableError($error, $stackPtr, 'RedundantFQN', $data);
                    if ($fix) {
                        $phpcsFile->fixer->replaceToken($prev, '');
                    }
                }
            } else {
                $error = 'PHP internal function "%s" must be imported';
                $data = [
                    $content,
                ];

                $fix = $phpcsFile->addFixableError($error, $stackPtr, 'ImportFQN', $data);
                if ($fix) {
                    $phpcsFile->fixer->beginChangeset();
                    $phpcsFile->fixer->replaceToken($prev, '');
                    $phpcsFile->fixer->endChangeset();

                    return $this->importFunction($content);
                }
            }
        } elseif ($namespacePtr !== null) {
            if (
                ! isset($this->importedFunctions[$content])
                && ! in_array($content, $this->exclude, true)
            ) {
                $error = 'PHP internal function "%s" must be imported';
                $data = [
                    $content,
                ];

                $fix = $phpcsFile->addFixableError($error, $stackPtr, 'Import', $data);
                if ($fix) {
                    return $this->importFunction($content);
                }
            }
        }

        return null;
    }

    private function importFunction(string $functionName): string
    {
        $this->importedFunctions[$functionName] = [
            'name' => $functionName,
            'fqn' => $functionName,
            'ptr' => 0
        ];

        return $functionName;
    }

    /**
     * @param string[] $functionNames
     */
    private function importFunctions(File $phpcsFile, int $namespacePtr, array $functionNames): void
    {
        if (! $functionNames) {
            return;
        }

        sort($functionNames);

        $phpcsFile->fixer->beginChangeset();

        /** @var array<int, array{code: int, content?: string, comment_closer?: int, scope_opener?: int}> $tokens */
        $tokens = $phpcsFile->getTokens();
        if (isset($tokens[$namespacePtr]['scope_opener'])) {
            $ptr = $tokens[$namespacePtr]['scope_opener'];
        } else {
            $ptr = $phpcsFile->findEndOfStatement($namespacePtr);
            $phpcsFile->fixer->addNewline($ptr);
        }

        $content = '';
        foreach ($functionNames as $functionName) {
            $content .= sprintf('%suse function %s;', $phpcsFile->eolChar, $functionName);
        }

        $phpcsFile->fixer->addContent($ptr, $content);

        $phpcsFile->fixer->endChangeset();
    }
}
