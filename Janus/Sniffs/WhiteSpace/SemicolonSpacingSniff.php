<?php declare(strict_types=1);


namespace Janus\Sniffs\WhiteSpace;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


/**
 * Checks that semicolons are correctly spaced and positioned.
 *
 * For normal semicolons, there must be no whitespace before them.
 * For semicolons terminating method chains, they must be on the same line for single-line chains
 *  and on the following line for multi-line chains.
 */
class SemicolonSpacingSniff implements Sniff {
	/**
	 * Lines that already reported a chain-semicolon positioning error.
	 *
	 * @var array<int, true>
	 */
	protected array $reportedChainSemicolonLines = [];


	/**
	 * Registers the tokens this sniff listens for.
	 *
	 * @return array<int>
	 */
	public function register(): array {
		return [
			T_SEMICOLON,
		];
	}


	/**
	 * Checks semicolon spacing.
	 *
	 * @param File $phpcsFile
	 * @param int $stackPtr
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		$previous = $this->findPreviousNonWhitespace(
			$tokens,
			$stackPtr - 1
		);

		if ($previous === null) {
			return;
		}

		/*
		 * Explicitly allow bracket-only line endings:
		 * - ");" as the only non-whitespace content of a line
		 * - ")" on its own line and ";" on the following line
		 */
		if ($tokens[ $previous ]['code'] === T_CLOSE_PARENTHESIS) {
			if ($this->isStandaloneClosingParenthesisSemicolonLine($tokens, $previous, $stackPtr)) {
				return;
			}

			if (
				$this->isStandaloneTokenLine($tokens, $previous, T_CLOSE_PARENTHESIS)
				&& $this->isStandaloneTokenLine($tokens, $stackPtr, T_SEMICOLON)
				&& $tokens[ $stackPtr ]['line'] === $tokens[ $previous ]['line'] + 1
			) {
				return;
			}
		}

		/*
		 * Special case:
		 *
		 * A semicolon ending a multi-method chain must be on
		 * its own line after the final method call.
		 */
		if ($this->isMethodChainTerminator($tokens, $previous)) {
			$this->checkMethodChainSemicolon($phpcsFile, $stackPtr, $previous);

			return;
		}

		/*
		 * Special case:
		 *
		 * For multiline concatenations, the semicolon must be on
		 * the following line.
		 */
		if ($this->isMultilineConcatenationTerminator($tokens, $stackPtr, $previous)) {
			$this->checkMultilineConcatenationSemicolon($phpcsFile, $stackPtr, $previous);

			return;
		}

		/*
		 * Normal semicolon:
		 *
		 * There must be no whitespace between the preceding
		 * expression and the semicolon.
		 */
		if ($previous + 1 === $stackPtr) {
			return;
		}

		$this->reportAndFix(
			$phpcsFile,
			$stackPtr,
			'There must be no whitespace before the semicolon.'
		);
	}


	/**
	 * @param array $tokens
	 * @param int $previousPtr
	 * @return bool
	 */
	protected function isMethodChainTerminator(array $tokens, int $previousPtr): bool {
		/*
		 * The semicolon must directly follow the closing parenthesis
		 * of a method call.
		 */
		if ($tokens[ $previousPtr ]['code'] !== T_CLOSE_PARENTHESIS) {
			return false;
		}

		$current = $previousPtr;

		/*
		 * Find the method name before the closing parenthesis.
		 */
		$openParenthesis = $tokens[ $current ]['parenthesis_opener'] ?? null;

		if ($openParenthesis === null) {
			return false;
		}

		$methodName = $this->findPreviousNonWhitespace($tokens, $openParenthesis - 1);

		if ($methodName === null) {
			return false;
		}

		/*
		 * Find the -> belonging to this method call.
		 */
		$operator = $this->findPreviousNonWhitespace($tokens, $methodName - 1);

		if (
			$operator === null
			|| (
				$tokens[ $operator ]['code'] !== T_OBJECT_OPERATOR
				&& $tokens[ $operator ]['code'] !== T_NULLSAFE_OBJECT_OPERATOR
			)
		) {
			return false;
		}

		/*
		 * Now inspect what is on the left side of this ->.
		 *
		 * For:
		 *
		 * $io->title()
		 *
		 * this is $io, so this is NOT a chain.
		 *
		 * For:
		 *
		 * $io->getWriter()->write()
		 *
		 * this is the closing ')' of getWriter(), so this IS a chain.
		 */
		$left = $this->findPreviousNonWhitespaceOrComment($tokens, $operator - 1);

		if ($left === null) {
			return false;
		}

		return $tokens[ $left ]['code'] === T_CLOSE_PARENTHESIS;
	}


	/**
	 * Checks the special semicolon position for method chains.
	 *
	 * @param File $phpcsFile
	 * @param int $semicolon
	 * @param int $lastCallEnd
	 * @return void
	 */
	protected function checkMethodChainSemicolon(File $phpcsFile, int $semicolon, int $lastCallEnd): void {
		$tokens = $phpcsFile->getTokens();
		$line = $tokens[ $semicolon ]['line'];

		if (isset($this->reportedChainSemicolonLines[ $line ])) {
			return;
		}

		$chainIsSingleLine = $this->isSingleLineMethodChain($tokens, $lastCallEnd);

		if ($chainIsSingleLine) {
			$isCorrectPosition = ($semicolon === $lastCallEnd + 1);
		}
		else {
			$isCorrectPosition = $tokens[ $semicolon ]['line'] === $tokens[ $lastCallEnd ]['line'] + 1;
		}

		if ($isCorrectPosition) {
			return;
		}

		$this->reportedChainSemicolonLines[ $line ] = true;

		$this->reportAndFix(
			$phpcsFile,
			$semicolon,
			'The semicolon terminating a method chain must be on the same line for single-line chains'
			. ' and on the following line for multi-line chains.',
			$lastCallEnd,
			!$chainIsSingleLine
		);
	}


	/**
	 * Checks the semicolon position for multiline concatenations.
	 *
	 * @param File $phpcsFile
	 * @param int $semicolon
	 * @param int $previousPtr
	 * @return void
	 */
	protected function checkMultilineConcatenationSemicolon(File $phpcsFile, int $semicolon, int $previousPtr): void {
		$tokens = $phpcsFile->getTokens();
		$isCorrectPosition = $tokens[ $semicolon ]['line'] === $tokens[ $previousPtr ]['line'] + 1;

		if ($isCorrectPosition) {
			return;
		}

		$this->reportAndFix(
			$phpcsFile,
			$semicolon,
			'The semicolon terminating a multiline concatenation must be on the following line.',
			$previousPtr,
			true
		);
	}


	/**
	 * Returns true when the semicolon terminates a multiline statement
	 * using at least one string-concatenation operator.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $semicolonPtr
	 * @param int $previousPtr
	 * @return bool
	 */
	protected function isMultilineConcatenationTerminator(array $tokens, int $semicolonPtr, int $previousPtr): bool {
		$statementStart = 0;

		for ($ptr = $previousPtr; $ptr >= 0; $ptr--) {
			if (
				$tokens[ $ptr ]['code'] === T_SEMICOLON
				|| $tokens[ $ptr ]['code'] === T_OPEN_TAG
				|| $tokens[ $ptr ]['code'] === T_OPEN_CURLY_BRACKET
			) {
				$statementStart = $ptr + 1;
				break;
			}
		}

		$firstStatementToken = $this->findNextNonWhitespace($tokens, $statementStart, $semicolonPtr);
		if ($firstStatementToken === null) {
			return false;
		}

		$nestingLevel = 0;

		for ($ptr = $firstStatementToken; $ptr < $semicolonPtr; $ptr++) {
			if ($this->isNestingOpener($tokens[ $ptr ]['code'])) {
				$nestingLevel++;
				continue;
			}

			if ($this->isNestingCloser($tokens[ $ptr ]['type'])) {
				$nestingLevel = max(0, $nestingLevel - 1);
				continue;
			}

			if ($tokens[ $ptr ]['code'] !== T_STRING_CONCAT) {
				continue;
			}

			/*
			 * Only treat concatenation as statement-terminating when it is
			 * part of the top-level statement expression.
			 */
			if ($nestingLevel !== 0) {
				continue;
			}

			$left = $this->findPreviousNonWhitespace($tokens, $ptr - 1);
			$right = $this->findNextNonWhitespace($tokens, $ptr + 1, $semicolonPtr);
			if ($left === null || $right === null) {
				continue;
			}

			if (
				$tokens[ $left ]['line'] !== $tokens[ $ptr ]['line']
				|| $tokens[ $right ]['line'] !== $tokens[ $ptr ]['line']
			) {
				return true;
			}
		}

		return false;
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
	 * Returns true when the expected token is the only non-whitespace token on its line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $tokenPtr
	 * @param string|int $expectedCode
	 * @return bool
	 */
	protected function isStandaloneTokenLine(array $tokens, int $tokenPtr, string|int $expectedCode): bool {
		if ($tokens[ $tokenPtr ]['code'] !== $expectedCode) {
			return false;
		}

		$line = $tokens[ $tokenPtr ]['line'];

		for ($ptr = $tokenPtr - 1; $ptr >= 0 && $tokens[ $ptr ]['line'] === $line; $ptr--) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return false;
			}
		}

		$tokenCount = count($tokens);
		for ($ptr = $tokenPtr + 1; $ptr < $tokenCount && $tokens[ $ptr ]['line'] === $line; $ptr++) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Returns true when a line contains only ");" (plus optional indentation).
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $closeParenthesisPtr
	 * @param int $semicolonPtr
	 * @return bool
	 */
	protected function isStandaloneClosingParenthesisSemicolonLine(
		array $tokens,
		int $closeParenthesisPtr,
		int $semicolonPtr
	): bool {
		if (
			$tokens[ $closeParenthesisPtr ]['code'] !== T_CLOSE_PARENTHESIS
			|| $tokens[ $semicolonPtr ]['code'] !== T_SEMICOLON
			|| $tokens[ $closeParenthesisPtr ]['line'] !== $tokens[ $semicolonPtr ]['line']
			|| $semicolonPtr !== $closeParenthesisPtr + 1
		) {
			return false;
		}

		$line = $tokens[ $closeParenthesisPtr ]['line'];

		for ($ptr = $closeParenthesisPtr - 1; $ptr >= 0 && $tokens[ $ptr ]['line'] === $line; $ptr--) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return false;
			}
		}

		$tokenCount = count($tokens);
		for ($ptr = $semicolonPtr + 1; $ptr < $tokenCount && $tokens[ $ptr ]['line'] === $line; $ptr++) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Determines whether the method chain ending at $lastCallEnd is
	 * entirely on one line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $lastCallEnd
	 * @return bool
	 */
	protected function isSingleLineMethodChain(array $tokens, int $lastCallEnd): bool {
		$line = $tokens[ $lastCallEnd ]['line'];
		$currentEnd = $lastCallEnd;

		while (true) {
			if ($tokens[ $currentEnd ]['line'] !== $line) {
				return false;
			}

			$openParenthesis =
				$tokens[ $currentEnd ]['parenthesis_opener'] ?? null;

			if ($openParenthesis === null) {
				return false;
			}

			$methodName = $this->findPreviousNonWhitespace($tokens, $openParenthesis - 1);

			if ($methodName === null || $tokens[ $methodName ]['line'] !== $line) {
				return false;
			}

			$operator = $this->findPreviousNonWhitespace($tokens, $methodName - 1);

			if (
				$operator === null
				|| $tokens[ $operator ]['line'] !== $line
				|| (
					$tokens[ $operator ]['code'] !== T_OBJECT_OPERATOR
					&& $tokens[ $operator ]['code'] !== T_NULLSAFE_OBJECT_OPERATOR
				)
			) {
				return false;
			}

			$left = $this->findPreviousNonWhitespace($tokens, $operator - 1);

			if ($left === null) {
				return false;
			}

			if ($tokens[ $left ]['code'] !== T_CLOSE_PARENTHESIS) {
				return $tokens[ $left ]['line'] === $line;
			}

			/*
			 * A closing ")" left of "->" only continues the method chain
			 * when that call itself is invoked via "->" or "?->".
			 *
			 * Examples that must STOP here:
			 * - new Collection($x)->groupBy(...)
			 * - factory()->groupBy(...)
			 * - Foo::make()->groupBy(...)
			 */
			if (!$this->isChainedCallEnd($tokens, $left)) {
				return $tokens[ $left ]['line'] === $line;
			}

			$currentEnd = $left;
		}
	}


	/**
	 * Returns true if the given ")" ends a call that itself is chained
	 * from an object/nullsafe operator.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $closeParenthesis
	 * @return bool
	 */
	protected function isChainedCallEnd(array $tokens, int $closeParenthesis): bool {
		if ($tokens[ $closeParenthesis ]['code'] !== T_CLOSE_PARENTHESIS) {
			return false;
		}

		$openParenthesis = $tokens[ $closeParenthesis ]['parenthesis_opener'] ?? null;

		if ($openParenthesis === null) {
			return false;
		}

		$methodName = $this->findPreviousNonWhitespace($tokens, $openParenthesis - 1);

		if ($methodName === null) {
			return false;
		}

		$operator = $this->findPreviousNonWhitespace($tokens, $methodName - 1);

		if ($operator === null) {
			return false;
		}

		return $tokens[ $operator ]['code'] === T_OBJECT_OPERATOR
			|| $tokens[ $operator ]['code'] === T_NULLSAFE_OBJECT_OPERATOR;
	}


	/**
	 * Reports a fixable error and removes invalid whitespace.
	 *
	 * @param File $phpcsFile
	 * @param int $stackPtr
	 * @param string $message
	 * @param int|null $previousPtr
	 * @param bool|null $placeOnNextLine
	 * @return void
	 */
	protected function reportAndFix(
		File $phpcsFile,
		int $stackPtr,
		string $message,
		?int $previousPtr = null,
		?bool $placeOnNextLine = null
	): void {
		$fix = $phpcsFile->addFixableError($message, $stackPtr, 'Incorrect');

		if (!$fix) {
			return;
		}

		$tokens = $phpcsFile->getTokens();

		/*
		 * Normal semicolon:
		 *
		 * Remove whitespace directly before it.
		 */
		if ($previousPtr === null) {
			$ptr = $stackPtr - 1;

			while ($ptr >= 0 && $tokens[ $ptr ]['code'] === T_WHITESPACE) {
				$phpcsFile->fixer->replaceToken(
					$ptr,
					''
				);

				$ptr--;
			}

			return;
		}

		/*
		 * Method-chain or multiline-concatenation semicolon:
		 *
		 * Remove whitespace between the final expression token and ';'.
		 * For multiline cases the semicolon moves to the next line.
		 */
		$phpcsFile->fixer->beginChangeset();

		for ($ptr = $previousPtr + 1; $ptr < $stackPtr; $ptr++) {
			if ($tokens[ $ptr ]['code'] === T_WHITESPACE) {
				$phpcsFile->fixer->replaceToken($ptr, '');
			}
		}

		if ($placeOnNextLine === true) {
			$phpcsFile->fixer->addContentBefore($stackPtr, PHP_EOL);
		}

		$phpcsFile->fixer->endChangeset();
	}


	/**
	 * Finds the next non-whitespace token up to (but excluding) $endPtr.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $startPtr
	 * @param int $endPtr
	 * @return int|null
	 */
	protected function findNextNonWhitespace(array $tokens, int $startPtr, int $endPtr): ?int {
		for ($ptr = $startPtr; $ptr < $endPtr; $ptr++) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return $ptr;
			}
		}

		return null;
	}


	/**
	 * Finds the previous non-whitespace token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int|null
	 */
	protected function findPreviousNonWhitespace(array $tokens, int $ptr): ?int {
		while ($ptr >= 0) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return $ptr;
			}

			$ptr--;
		}

		return null;
	}


	/**
	 * Finds the previous token that is neither whitespace nor a comment token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int|null
	 */
	protected function findPreviousNonWhitespaceOrComment(array $tokens, int $ptr): ?int {
		while ($ptr >= 0) {
			$code = $tokens[ $ptr ]['code'];
			$type = (string)($tokens[ $ptr ]['type'] ?? '');

			if (
				$code === T_WHITESPACE
				|| $code === T_COMMENT
				|| str_starts_with($type, 'T_DOC_COMMENT')
			) {
				$ptr--;
				continue;
			}

			return $ptr;
		}

		return null;
	}
}
