<?php declare(strict_types=1);


namespace Janus\Sniffs\WhiteSpace;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;


/**
 * Enforces consistent indentation for multiline short arrays used in array union expressions.
 *
 * Example:
 * `$config = [ ... ] + $defaults;`
 */
class ArrayUnionIndentationSniff implements Sniff {
	/**
	 * @return array<int>
	 */
	public function register(): array {
		return [
			T_OPEN_SHORT_ARRAY,
		];
	}


	/**
	 * @param File $phpcsFile
	 * @param int $stackPtr
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();
		$closer = $tokens[ $stackPtr ]['bracket_closer'] ?? null;

		if (!is_int($closer)) {
			return;
		}

		if ($tokens[ $stackPtr ]['line'] === $tokens[ $closer ]['line']) {
			return;
		}

		$nextNonEmpty = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, $closer + 1, null, true);
		if (!is_int($nextNonEmpty)) {
			return;
		}

		if ($tokens[ $nextNonEmpty ]['line'] !== $tokens[ $closer ]['line']) {
			return;
		}

		$isArrayUnionContext = $tokens[ $nextNonEmpty ]['code'] === T_PLUS;

		$previousNonEmpty = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, $stackPtr - 1, null, true);
		$isCallArgumentArrayContext = (
			$tokens[ $nextNonEmpty ]['code'] === T_CLOSE_PARENTHESIS
			&& is_int($previousNonEmpty)
			&& $tokens[ $previousNonEmpty ]['line'] === $tokens[ $stackPtr ]['line']
			&& $tokens[ $previousNonEmpty ]['code'] === T_COMMA
		);

		if (!$isArrayUnionContext && !$isCallArgumentArrayContext) {
			return;
		}

		$baseIndent = $this->getLineIndentation($tokens, $stackPtr);
		$closingIndent = $this->getLineIndentation($tokens, $closer);
		$expectedOverIndented = $baseIndent;

		if ($closingIndent === $expectedOverIndented) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'Multiline inline array must align closing bracket with base indentation and indent entries by one level.',
			$closer,
			'InvalidArrayUnionIndentation'
		);

		if (!$fix) {
			return;
		}

		$startLine = $tokens[ $stackPtr ]['line'] + 1;
		$endLine = $tokens[ $closer ]['line'];

		$phpcsFile->fixer->beginChangeset();

		for ($line = $startLine; $line <= $endLine; $line++) {
			$lineStartPtr = $this->findLineStartPointer($tokens, $line);
			if (!is_int($lineStartPtr) || $tokens[ $lineStartPtr ]['code'] !== T_WHITESPACE) {
				continue;
			}

			$newContent = $this->removeOneIndentLevelFromWhitespace($tokens[ $lineStartPtr ]['content']);
			if ($newContent === $tokens[ $lineStartPtr ]['content']) {
				continue;
			}

			$phpcsFile->fixer->replaceToken($lineStartPtr, $newContent);
		}

		$phpcsFile->fixer->endChangeset();
	}


	/**
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return string
	 */
	protected function getLineIndentation(array $tokens, int $ptr): string {
		$line = $tokens[ $ptr ]['line'];
		$lineStartPtr = $this->findLineStartPointer($tokens, $line);

		if (!is_int($lineStartPtr) || $tokens[ $lineStartPtr ]['code'] !== T_WHITESPACE) {
			return '';
		}

		$content = $tokens[ $lineStartPtr ]['content'];
		$lineBreakPos = strrpos($content, "\n");

		if ($lineBreakPos === false) {
			$lineBreakPos = strrpos($content, "\r");
		}

		if ($lineBreakPos === false) {
			return $content;
		}

		return substr($content, $lineBreakPos + 1);
	}


	/**
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $line
	 * @return int|null
	 */
	protected function findLineStartPointer(array $tokens, int $line): ?int {
		$tokenCount = count($tokens);
		for ($ptr = 0; $ptr < $tokenCount; $ptr++) {
			$currentLine = $tokens[ $ptr ]['line'];
			if ($currentLine === $line) {
				return $ptr;
			}

			if ($currentLine > $line) {
				break;
			}
		}

		return null;
	}


	/**
	 * @param string $whitespace
	 * @return string
	 */
	protected function removeOneIndentLevelFromWhitespace(string $whitespace): string {
		$lineBreakPos = strrpos($whitespace, "\n");
		if ($lineBreakPos === false) {
			$lineBreakPos = strrpos($whitespace, "\r");
		}

		$prefix = '';
		$indent = $whitespace;
		if ($lineBreakPos !== false) {
			$prefix = substr($whitespace, 0, $lineBreakPos + 1);
			$indent = substr($whitespace, $lineBreakPos + 1);
		}

		if (str_starts_with($indent, "\t")) {
			return $prefix . substr($indent, 1);
		}

		if (str_starts_with($indent, '    ')) {
			return $prefix . substr($indent, 4);
		}

		return $whitespace;
	}
}
