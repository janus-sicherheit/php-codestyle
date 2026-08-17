<?php declare(strict_types=1);


namespace Janus\Sniffs\Functions;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


/**
 * Enforces formatting of arrow functions (`fn`):
 *
 * - No whitespace is allowed between the `fn` keyword and the opening parenthesis.
 * - Exactly one space is required before and after `=>`.
 * - When the expression is wrapped onto a new line, `=>` itself must move to
 *   that new line (never left dangling at the end of the first line) and must
 *   be indented exactly one level deeper than the statement it belongs to.
 */
class ArrowFunctionDeclarationSniff implements Sniff {
	/**
	 * @return array<int, (int|string)>
	 */
	public function register(): array {
		return [T_FN];
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $stackPtr
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		$this->processFnKeywordSpacing($phpcsFile, $tokens, $stackPtr);

		// For a genuine arrow function, PHP_CodeSniffer sets the "=>" itself
		// as the scope opener of the T_FN token, so no manual search is
		// needed. The explicit T_FN_ARROW check is a defensive sanity check.
		$arrow = $tokens[ $stackPtr ]['scope_opener'] ?? false;
		if ($arrow === false || $tokens[ $arrow ]['code'] !== T_FN_ARROW) {
			return;
		}

		$this->processBeforeArrow($phpcsFile, $tokens, $stackPtr, $arrow);
		$this->processAfterArrow($phpcsFile, $tokens, $stackPtr, $arrow);
	}


	/**
	 * Ensures there is no whitespace between the `fn` keyword and the opening parenthesis.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $stackPtr
	 * @return void
	 */
	protected function processFnKeywordSpacing(File $phpcsFile, array $tokens, int $stackPtr): void {
		$next = $stackPtr + 1;
		if (isset($tokens[ $next ]) === false || $tokens[ $next ]['code'] !== T_WHITESPACE) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'There must be no whitespace between the "fn" keyword and the opening parenthesis.',
			$next,
			'SpaceAfterFn'
		);

		if ($fix === true) {
			$phpcsFile->fixer->replaceToken($next, '');
		}
	}


	/**
	 * Checks and fixes spacing, or line placement/indentation, before "=>".
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $stackPtr
	 * @param int $arrow
	 * @return void
	 */
	protected function processBeforeArrow(File $phpcsFile, array $tokens, int $stackPtr, int $arrow): void {
		$prevContent = $phpcsFile->findPrevious(T_WHITESPACE, $arrow - 1, null, true);
		if ($prevContent === false) {
			return;
		}

		$sameLine = $tokens[ $prevContent ]['line'] === $tokens[ $arrow ]['line'];

		if ($sameLine === true) {
			$spacing = $this->getContentBetween($tokens, $prevContent, $arrow);

			if ($spacing !== ' ') {
				$fix = $phpcsFile->addFixableError(
					'Expected exactly one space before "=>"; found %s.',
					$arrow,
					'SpacingBefore',
					[$spacing === '' ? 'none' : strlen($spacing) . ' spaces']
				);

				if ($fix === true) {
					$phpcsFile->fixer->beginChangeset();
					$this->setContentBetween($phpcsFile, $prevContent, $arrow, ' ');
					$phpcsFile->fixer->endChangeset();
				}
			}

			return;
		}

		$expectedIndent = $this->getExpectedIndent($phpcsFile, $tokens, $stackPtr);
		$actualIndent = $tokens[ $arrow ]['column'] - 1;

		if ($actualIndent === $expectedIndent) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'Expected "=>" to be indented %s spaces; found %s.',
			$arrow,
			'ArrowIndent',
			[$expectedIndent, $actualIndent]
		);

		if ($fix === true) {
			$phpcsFile->fixer->beginChangeset();
			$this->setContentBetween(
				$phpcsFile,
				$prevContent,
				$arrow,
				$phpcsFile->eolChar . str_repeat(' ', $expectedIndent)
			);
			$phpcsFile->fixer->endChangeset();
		}
	}


	/**
	 * Checks and fixes spacing after "=>", including the "dangling arrow" case.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $stackPtr
	 * @param int $arrow
	 * @return void
	 */
	protected function processAfterArrow(File $phpcsFile, array $tokens, int $stackPtr, int $arrow): void {
		$nextContent = $phpcsFile->findNext(T_WHITESPACE, $arrow + 1, null, true);
		if ($nextContent === false) {
			return;
		}

		$sameLine = $tokens[ $arrow ]['line'] === $tokens[ $nextContent ]['line'];

		if ($sameLine === false) {
			$fix = $phpcsFile->addFixableError(
				'The "=>" must not be left dangling at the end of the line; it must wrap onto the next line instead.',
				$arrow,
				'ArrowNotOnSecondLine'
			);

			if ($fix === true) {
				$prevContent = $phpcsFile->findPrevious(T_WHITESPACE, $arrow - 1, null, true);
				$expectedIndent = $this->getExpectedIndent($phpcsFile, $tokens, $stackPtr);

				$phpcsFile->fixer->beginChangeset();

				if ($prevContent !== false) {
					$this->setContentBetween($phpcsFile, $prevContent, $arrow, '');
				}

				$phpcsFile->fixer->replaceToken($arrow, '');
				$this->setContentBetween(
					$phpcsFile,
					$arrow,
					$nextContent,
					$phpcsFile->eolChar . str_repeat(' ', $expectedIndent) . '=> '
				);

				$phpcsFile->fixer->endChangeset();
			}

			return;
		}

		$spacing = $this->getContentBetween($tokens, $arrow, $nextContent);

		if ($spacing !== ' ') {
			$fix = $phpcsFile->addFixableError(
				'Expected exactly one space after "=>"; found %s.',
				$arrow,
				'SpacingAfter',
				[$spacing === '' ? 'none' : strlen($spacing) . ' spaces']
			);

			if ($fix === true) {
				$phpcsFile->fixer->beginChangeset();
				$this->setContentBetween($phpcsFile, $arrow, $nextContent, ' ');
				$phpcsFile->fixer->endChangeset();
			}
		}
	}


	/**
	 * Concatenates the raw content of every token strictly between two boundary tokens.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $start
	 * @param int $end
	 * @return string
	 */
	protected function getContentBetween(array $tokens, int $start, int $end): string {
		$content = '';
		for ($i = $start + 1; $i < $end; $i++) {
			$content .= $tokens[ $i ]['content'];
		}

		return $content;
	}


	/**
	 * Replaces everything strictly between two boundary tokens with a single
	 * piece of content, collapsing multiple whitespace tokens (e.g. across a
	 * line break) into one, or inserting content where none existed before.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $start
	 * @param int $end
	 * @param string $content
	 * @return void
	 */
	protected function setContentBetween(File $phpcsFile, int $start, int $end, string $content): void {
		if ($start + 1 === $end) {
			if ($content !== '') {
				$phpcsFile->fixer->addContentBefore($end, $content);
			}

			return;
		}

		$first = true;
		for ($i = $start + 1; $i < $end; $i++) {
			$phpcsFile->fixer->replaceToken($i, $first === true ? $content : '');
			$first = false;
		}
	}


	/**
	 * Determines the expected indentation (in spaces) for a wrapped "=>",
	 * relative to the start of the line the `fn` keyword is on.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $stackPtr
	 * @return int
	 */
	protected function getExpectedIndent(File $phpcsFile, array $tokens, int $stackPtr): int {
		$line = $tokens[ $stackPtr ]['line'];
		$firstOnLine = $stackPtr;

		for ($i = $stackPtr - 1; $i >= 0; $i--) {
			if ($tokens[ $i ]['line'] !== $line) {
				break;
			}

			$firstOnLine = $i;
		}

		// A leading T_WHITESPACE token always starts at column 1, so it does not represent the line's indentation depth.
		// Skip forward to the first real content token and use its column instead.
		if ($tokens[ $firstOnLine ]['code'] === T_WHITESPACE) {
			$firstOnLine = $phpcsFile->findNext(T_WHITESPACE, $firstOnLine, null, true);
		}

		$baseIndent = $tokens[ $firstOnLine ]['column'] - 1;

		return $baseIndent + $this->getIndentSize($phpcsFile);
	}


	/**
	 * Reads the indent size the run is already configured with (--tab-width),
	 * rather than introducing a separate, redundant sniff property.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @return int
	 */
	protected function getIndentSize(File $phpcsFile): int {
		$tabWidth = $phpcsFile->config->tabWidth;
		if ($tabWidth < 1) {
			return 4;
		}

		return $tabWidth;
	}
}
