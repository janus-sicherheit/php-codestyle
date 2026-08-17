<?php /** @noinspection PhpMissingDocCommentInspection, PhpMissingClassConstantTypeInspection */


declare(strict_types=1); // phpcs:ignore


namespace Janus\Sniffs\WhiteSpace;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


/**
 * Ensures that there is exactly one space between type hint and constant name in constant declarations.
 */
class ConstantDeclarationSpacingSniff implements Sniff {
	/**
	 * @return array|array<int>|array<string>
	 */
	public function register(): array {
		return [
			T_CONST,
		];
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $stackPtr
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		$nextPtr = $stackPtr + 1;

		// Skip whitespace directly after "const".
		$nextPtr = $this->skipWhitespace($tokens, $nextPtr);

		if (!isset($tokens[ $nextPtr ])) {
			return;
		}

		/*
		 * Untyped constant:
		 *
		 * const FOO = 'foo';
		 *
		 * Typed constant:
		 *
		 * const string FOO = 'foo';
		 * const ?Foo FOO = 'foo';
		 * const Foo|Bar FOO = 'foo';
		 */
		$constantPtr = $this->findConstantName($phpcsFile, $nextPtr);

		if ($constantPtr === null) {
			return;
		}

		$whitespacePtr = $constantPtr - 1;

		if ($tokens[ $whitespacePtr ]['code'] !== T_WHITESPACE) {
			return;
		}

		if ($tokens[ $whitespacePtr ]['content'] !== ' ') {
			$fix = $phpcsFile->addFixableError(
				'There must be exactly one space between type hint and constant name.',
				$whitespacePtr,
				'Spacing'
			);

			if ($fix) {
				$phpcsFile->fixer->replaceToken($whitespacePtr, ' ');
			}
		}

		/*
		 * Check additional constants in:
		 *
		 * const string FOO = 1, BAR = 2;
		 */
		$this->processAdditionalConstants($phpcsFile, $constantPtr);
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $startPtr
	 * @return int|null
	 */
	protected function findConstantName(File $phpcsFile, int $startPtr): ?int {
		$tokens = $phpcsFile->getTokens();

		/*
		 * If the first token is T_STRING, it can either be:
		 *
		 * const FOO
		 *
		 * or:
		 *
		 * const string FOO
		 *
		 * We determine that by looking ahead.
		 */

		$ptr = $startPtr;

		while (isset($tokens[ $ptr ])) {
			$code = $tokens[ $ptr ]['code'];

			if ($code === T_WHITESPACE) {
				$ptr++;
				continue;
			}

			if ($code === T_STRING) {
				$next = $this->skipWhitespace($tokens, $ptr + 1);

				if (
					isset($tokens[ $next ])
					&& in_array(
						$tokens[ $next ]['code'],
						[
							T_VARIABLE,
							T_STRING,
						],
						true
					)
				) {
					return $next;
				}

				return $ptr;
			}

			if (
				$code === T_NULLABLE
				|| $code === T_NS_SEPARATOR
				|| $code === T_NAME_QUALIFIED
				|| $code === T_NAME_FULLY_QUALIFIED
			) {
				$ptr++;
				continue;
			}

			return null;
		}

		return null;
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $constantPtr
	 * @return void
	 */
	protected function processAdditionalConstants(File $phpcsFile, int $constantPtr): void {
		$tokens = $phpcsFile->getTokens();

		$ptr = $constantPtr;
		$nestingLevel = 0;

		while (isset($tokens[ $ptr ])) {
			if ($this->isNestingOpener($tokens[ $ptr ]['code'])) {
				$nestingLevel++;
				$ptr++;
				continue;
			}

			if ($this->isNestingCloser($tokens[ $ptr ]['code'])) {
				$nestingLevel = max(0, $nestingLevel - 1);
				$ptr++;
				continue;
			}

			if ($tokens[ $ptr ]['code'] === T_COMMA) {
				// Only commas on declaration level can separate additional constants.
				if ($nestingLevel !== 0) {
					$ptr++;
					continue;
				}

				$namePtr = $this->skipWhitespace($tokens, $ptr + 1);

				if (!isset($tokens[ $namePtr ])) {
					return;
				}

				// Not a second constant declaration (e.g. expression/array item).
				if ($tokens[ $namePtr ]['code'] !== T_STRING) {
					$ptr++;
					continue;
				}

				$whitespacePtr = $namePtr - 1;

				if (
					$tokens[ $whitespacePtr ]['code'] === T_WHITESPACE
					&& $tokens[ $whitespacePtr ]['content'] !== ' '
				) {
					$phpcsFile->addError(
						'There must be exactly one space before constant name.',
						$whitespacePtr,
						'Spacing'
					);
				}
			}

			if ($tokens[ $ptr ]['code'] === T_SEMICOLON) {
				return;
			}

			$ptr++;
		}
	}


	/**
	 * @param string|int $code
	 * @return bool
	 */
	protected function isNestingOpener(string|int $code): bool {
		return in_array(
			$code,
			[
				T_OPEN_PARENTHESIS,
				T_OPEN_SHORT_ARRAY,
				T_OPEN_SQUARE_BRACKET,
				T_OPEN_CURLY_BRACKET,
				'T_OPEN_PARENTHESIS',
				'T_OPEN_SHORT_ARRAY',
				'T_OPEN_SQUARE_BRACKET',
				'T_OPEN_CURLY_BRACKET',
			],
			true
		);
	}


	/**
	 * @param string|int $code
	 * @return bool
	 */
	protected function isNestingCloser(string|int $code): bool {
		return in_array(
			$code,
			[
				T_CLOSE_PARENTHESIS,
				T_CLOSE_SHORT_ARRAY,
				T_CLOSE_SQUARE_BRACKET,
				T_CLOSE_CURLY_BRACKET,
				'T_CLOSE_PARENTHESIS',
				'T_CLOSE_SHORT_ARRAY',
				'T_CLOSE_SQUARE_BRACKET',
				'T_CLOSE_CURLY_BRACKET',
			],
			true
		);
	}


	/**
	 * @param array $tokens
	 * @param int $ptr
	 * @return int
	 */
	protected function skipWhitespace(array $tokens, int $ptr): int {
		while (
			isset($tokens[ $ptr ])
			&& $tokens[ $ptr ]['code'] === T_WHITESPACE
		) {
			$ptr++;
		}

		return $ptr;
	}
}
