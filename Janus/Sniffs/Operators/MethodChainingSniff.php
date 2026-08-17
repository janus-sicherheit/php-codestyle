<?php declare(strict_types=1);


namespace Janus\Sniffs\Operators;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


/**
 * Checks method chaining formatting.
 */
class MethodChainingSniff implements Sniff {
	/**
	 * Maximum allowed line length for keeping a chain inline.
	 *
	 * @var int
	 */
	protected const int MAX_INLINE_CHAIN_LENGTH = 140;
	/**
	 * Maximum allowed operator count for keeping a chain inline.
	 * Includes object operators and an optional static operator.
	 *
	 * @var int
	 */
	protected const int MAX_INLINE_CHAIN_OPERATORS = 2;
	/**
	 * Object operator token used by normal method/property access.
	 *
	 * @var int
	 */
	protected const int OBJECT_OPERATOR = T_OBJECT_OPERATOR;
	/**
	 * Nullsafe object operator
	 *
	 * @var int
	 */
	protected const int NULLSAFE_OBJECT_OPERATOR = T_NULLSAFE_OBJECT_OPERATOR;


	/**
	 * Already processed chains.
	 *
	 * @var array<int, true>
	 */
	protected array $processedChains = [];


	/**
	 * Registers the tokens this sniff listens for.
	 *
	 * @return array<int>
	 */
	public function register(): array {
		return [
			self::OBJECT_OPERATOR,
			self::NULLSAFE_OBJECT_OPERATOR,
		];
	}


	/**
	 * Checks method chaining.
	 *
	 * @param File $phpcsFile File being scanned.
	 * @param int $stackPtr Current object operator.
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		$chainStart = $this->findChainStart($tokens, $stackPtr);

		if (isset($this->processedChains[ $chainStart ])) {
			return;
		}

		$chain = $this->getMethodChain($tokens, $chainStart);

		if ($chain === []) {
			return;
		}

		$this->processedChains[ $chainStart ] = true;

		$isStaticStartChain = $this->hasStaticStartCall($tokens, $chain[0]);

		$totalChainOperators = count($chain) + ($isStaticStartChain ? 1 : 0);

		$inlineViolationMessage = $this->getInlineViolationMessage($tokens, $chain, $totalChainOperators);

		// A single method call must not be split before the operator.
		if (
			count($chain) === 1
			&& !$isStaticStartChain
		) {
			$this->checkSingleCallLine($phpcsFile, $chain[0]);

			return;
		}

		if ($this->canStayInline($tokens, $chain, $totalChainOperators)) {
			return;
		}

		if ($totalChainOperators < 2) {
			return;
		}

		$this->checkChain(
			$phpcsFile,
			$chain,
			$totalChainOperators,
			$inlineViolationMessage
		);
	}


	/**
	 * Finds the first object operator of the current chain.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $stackPtr
	 * @return int
	 */
	protected function findChainStart(array $tokens, int $stackPtr): int {
		$current = $stackPtr;

		while (true) {
			$previous = $this->findPreviousCodeToken($tokens, $current - 1);

			/*
			 * The previous expression must be a method call ending
			 * with a closing parenthesis.
			 */
			if (
				$previous === null
				|| $tokens[ $previous ]['code'] !== T_CLOSE_PARENTHESIS
			) {
				return $current;
			}

			$openParenthesis = $tokens[ $previous ]['parenthesis_opener'] ?? null;

			if ($openParenthesis === null) {
				return $current;
			}

			/*
			 * Before the opening parenthesis we expect a method name.
			 */
			$methodName = $this->findPreviousCodeToken($tokens, $openParenthesis - 1);

			if ($methodName === null || !$this->isMethodNameToken($tokens[ $methodName ]['code'])) {
				return $current;
			}

			$previousOperator = $this->findPreviousCodeToken($tokens, $methodName - 1);

			if (
				$previousOperator === null
				|| !$this->isObjectOperator($tokens[ $previousOperator ]['code'])
			) {
				return $current;
			}

			$current = $previousOperator;
		}
	}


	/**
	 * Returns all object operators belonging to one method chain.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $chainStart
	 * @return array<int, int>
	 */
	protected function getMethodChain(array $tokens, int $chainStart): array {
		$operators = [];
		$operator = $chainStart;

		while (true) {
			$callEnd = $this->getMethodCallEnd($tokens, $operator);

			if ($callEnd === null) {
				break;
			}

			$operators[] = $operator;

			$next = $this->findNextCodeToken($tokens, $callEnd + 1);

			if (
				$next === null
				|| !$this->isObjectOperator($tokens[ $next ]['code'])
			) {
				break;
			}

			if ($this->getMethodCallEnd($tokens, $next) === null) {
				break;
			}

			$operator = $next;
		}

		return $operators;
	}


	/**
	 * Returns the closing parenthesis of the method call after an
	 * object operator.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $operatorPtr
	 * @return int|null
	 */
	protected function getMethodCallEnd(array $tokens, int $operatorPtr): ?int {
		$methodName = $this->findNextNonWhitespace(
			$tokens,
			$operatorPtr + 1
		);

		if (
			$methodName === null
			|| !$this->isMethodNameToken($tokens[ $methodName ]['code'])
		) {
			return null;
		}

		$openParenthesis = $this->findNextNonWhitespace($tokens, $methodName + 1);

		if (
			$openParenthesis === null
			|| $tokens[ $openParenthesis ]['code'] !== T_OPEN_PARENTHESIS
		) {
			return null;
		}

		return $tokens[ $openParenthesis ]['parenthesis_closer'] ?? null;
	}


	/**
	 * @param array $tokens
	 * @param int $firstOperator
	 * @return bool
	 */
	protected function hasStaticStartCall(array $tokens, int $firstOperator): bool {
		$previous = $this->findPreviousCodeToken($tokens, $firstOperator - 1);

		if (
			$previous === null
			|| $tokens[ $previous ]['code'] !== T_CLOSE_PARENTHESIS
		) {
			return false;
		}

		$openParenthesis =
			$tokens[ $previous ]['parenthesis_opener'] ?? null;

		if ($openParenthesis === null) {
			return false;
		}

		$methodName = $this->findPreviousCodeToken($tokens, $openParenthesis - 1);

		if (
			$methodName === null
			|| !$this->isMethodNameToken($tokens[ $methodName ]['code'])
		) {
			return false;
		}

		$operator = $this->findPreviousCodeToken($tokens, $methodName - 1);

		if ($operator === null) {
			return false;
		}

		return $tokens[ $operator ]['code'] === T_DOUBLE_COLON;
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $operator
	 * @return void
	 */
	protected function checkSingleCallLine(File $phpcsFile, int $operator): void {
		$tokens = $phpcsFile->getTokens();

		$previous = $this->findPreviousNonWhitespace($tokens, $operator - 1);

		if ($previous === null) {
			return;
		}

		if ($tokens[ $previous ]['line'] === $tokens[ $operator ]['line']) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'A single method call must not be split before the -> operator.',
			$operator,
			'SingleCall'
		);

		if (!$fix) {
			return;
		}

		/*
		 * Remove all whitespace between expression and ->.
		 */
		for ($ptr = $previous + 1; $ptr < $operator; $ptr++) {
			if ($tokens[ $ptr ]['code'] === T_WHITESPACE) {
				$phpcsFile->fixer->replaceToken($ptr, '');
			}
		}
	}


	/**
	 * Checks formatting of all operators in a method chain.
	 *
	 * @param File $phpcsFile
	 * @param array<int, int> $chain
	 * @param int $totalChainOperators
	 * @param string|null $inlineViolationMessage
	 * @return void
	 */
	protected function checkChain(File $phpcsFile, array $chain, int $totalChainOperators, ?string $inlineViolationMessage = null): void {
		$tokens = $phpcsFile->getTokens();

		$firstOperator = $chain[0];

		/*
		 * Determine indentation from the line on which the chain
		 * starts, i.e. the line containing "$foo = $this".
		 */
		$baseIndentReference = $this->findPreviousCodeToken($tokens, $firstOperator - 1);

		if ($baseIndentReference === null) {
			$baseIndentReference = $firstOperator;
		}

		$baseIndent = $this->getLineIndent($tokens, $baseIndentReference);
		$tabWidth = max(1, $phpcsFile->config->tabWidth);
		$baseIndentWidth = $this->getIndentWidth($baseIndent, $tabWidth);
		$requiredIndentWidth = $baseIndentWidth + $tabWidth;

		$requiredIndent = $baseIndent . "\t";

		if (
			$inlineViolationMessage !== null
			&& $this->isSingleLineChain($tokens, $chain)
		) {
			$fix = $phpcsFile->addFixableError($inlineViolationMessage, $chain[0], 'InlinePolicy');

			if ($fix) {
				foreach ($chain as $operator) {
					$lineStart = $this->findLineStart($tokens, $operator);

					$this->fixOperator($phpcsFile, $operator, $lineStart, $requiredIndent);
				}
			}

			$this->checkSemicolon($phpcsFile, $chain, $totalChainOperators);

			return;
		}

		$ownLineViolations = [];
		$indentViolations = [];

		foreach ($chain as $operator) {
			$previous = $this->findPreviousNonWhitespace($tokens, $operator - 1);

			if ($previous === null) {
				continue;
			}

			$lineStart = $this->findLineStart($tokens, $operator);

			$actualPrefix = '';

			for ($ptr = $lineStart; $ptr < $operator; $ptr++) {
				if ($tokens[ $ptr ]['code'] === T_WHITESPACE) {
					$actualPrefix .= $tokens[ $ptr ]['content'];
				}
				else {
					/*
					 * There is code before the object operator on
					 * this line. Therefore, the operator is not at
					 * the beginning of the line.
					 */
					$actualPrefix = null;
					break;
				}
			}

			$isNewLine = $this->getLineNumber($tokens, $previous) < $this->getLineNumber($tokens, $operator);

			$actualPrefixWidth = null;

			if ($actualPrefix !== null) {
				$actualPrefixWidth = $this->getIndentWidth($actualPrefix, $tabWidth);
			}

			if (
				$isNewLine
				&& $actualPrefixWidth === $requiredIndentWidth
			) {
				continue;
			}

			if (!$isNewLine) {
				$ownLineViolations[] = [
					'operator' => $operator,
					'lineStart' => $lineStart,
				];
				continue;
			}

			if ($actualPrefixWidth !== $requiredIndentWidth) {
				$indentViolations[] = [
					'operator' => $operator,
					'lineStart' => $lineStart,
				];
			}
		}

		if ($ownLineViolations !== []) {
			$firstViolation = $ownLineViolations[0];
			$fix = $phpcsFile->addFixableError(
				'Each method in a chain of two or more method calls must start with "->" on its own line.',
				$firstViolation['operator'],
				'OperatorOwnLine'
			);

			if ($fix) {
				foreach ($ownLineViolations as $violation) {
					$this->fixOperator(
						$phpcsFile,
						$violation['operator'],
						$violation['lineStart'],
						$requiredIndent
					);
				}
			}
		}

		if ($indentViolations !== []) {
			$firstViolation = $indentViolations[0];
			$fix = $phpcsFile->addFixableError(
				'Chained method operators must be indented by exactly one additional level relative to the chain start.',
				$firstViolation['operator'],
				'OperatorIndent'
			);

			if ($fix) {
				foreach ($indentViolations as $violation) {
					$this->fixOperator($phpcsFile, $violation['operator'], $violation['lineStart'], $requiredIndent);
				}
			}
		}

		$this->checkSemicolon($phpcsFile, $chain, $totalChainOperators);
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array $chain
	 * @param int $totalChainOperators
	 * @return void
	 */
	protected function checkSemicolon(File $phpcsFile, array $chain, int $totalChainOperators): void {
		$tokens = $phpcsFile->getTokens();

		if ($totalChainOperators < 2) {
			return;
		}

		$lastOperator = $chain[ count($chain) - 1 ];

		$lastCallEnd = $this->getMethodCallEnd($tokens, $lastOperator);

		if ($lastCallEnd === null) {
			return;
		}

		$semicolon = $this->findNextNonWhitespace($tokens, $lastCallEnd + 1);

		if ($semicolon === null) {
			return;
		}

		if ($tokens[ $semicolon ]['code'] !== T_SEMICOLON) {
			return;
		}

		$lastCallLine = $tokens[ $lastCallEnd ]['line'];
		$semicolonLine = $tokens[ $semicolon ]['line'];

		/*
		 * The semicolon must be on the line immediately following
		 * the final method call.
		 */
		if ($semicolonLine === $lastCallLine + 1) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'The semicolon terminating a method chain must be on the line following the final method call.',
			$semicolon,
			'Semicolon'
		);

		if (!$fix) {
			return;
		}

		$this->fixSemicolon($phpcsFile, $semicolon, $lastCallEnd);
	}


	/**
	 * Fixes one object operator's position and indentation.
	 *
	 * @param File $phpcsFile
	 * @param int $operatorPtr
	 * @param int $lineStart
	 * @param string $requiredIndent
	 * @return void
	 */
	protected function fixOperator(File $phpcsFile, int $operatorPtr, int $lineStart, string $requiredIndent): void {
		$tokens = $phpcsFile->getTokens();

		$phpcsFile->fixer->beginChangeset();

		/*
		 * If there is whitespace directly before the operator on the
		 * same line, remove it first.
		 */
		$previous = $operatorPtr - 1;

		while (
			$previous >= $lineStart
			&& $tokens[ $previous ]['code'] === T_WHITESPACE
		) {
			$phpcsFile->fixer->replaceToken($previous, '');

			$previous--;
		}

		/*
		 * If the operator is currently on the same line as code,
		 * insert a newline before it.
		 */
		$previous = $this->findPreviousNonWhitespace($tokens, $operatorPtr - 1);

		if (
			$previous !== null
			&& $tokens[ $previous ]['line'] === $tokens[ $operatorPtr ]['line']
		) {
			$phpcsFile->fixer->addContentBefore($operatorPtr, PHP_EOL . $requiredIndent);

			// Keep multiline method-call arguments one level deeper after moving the operator.
			$this->indentMultilineCallAfterOperatorMove($phpcsFile, $tokens, $operatorPtr, "\t");
		}
		else {
			/*
			 * Already on another line: normalize the indentation
			 * before the operator.
			 */
			$start = $operatorPtr - 1;

			while (
				$start >= 0
				&& $tokens[ $start ]['line'] === $tokens[ $operatorPtr ]['line']
				&& $tokens[ $start ]['code'] === T_WHITESPACE
			) {
				$start--;
			}

			for ($ptr = $start + 1; $ptr < $operatorPtr; $ptr++) {
				if ($tokens[ $ptr ]['code'] === T_WHITESPACE) {
					$phpcsFile->fixer->replaceToken($ptr, '');
				}
			}

			$phpcsFile->fixer->addContentBefore($operatorPtr, $requiredIndent);
		}

		$phpcsFile->fixer->endChangeset();
	}


	/**
	 * Adds one indentation level to all lines within a multiline method call.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $operatorPtr
	 * @param string $indentUnit
	 * @return void
	 */
	protected function indentMultilineCallAfterOperatorMove(File $phpcsFile, array $tokens, int $operatorPtr, string $indentUnit): void {
		$methodName = $this->findNextNonWhitespace($tokens, $operatorPtr + 1);

		if ($methodName === null || !$this->isMethodNameToken($tokens[ $methodName ]['code'])) {
			return;
		}

		$openParenthesis = $this->findNextNonWhitespace($tokens, $methodName + 1);

		if (
			$openParenthesis === null
			|| $tokens[ $openParenthesis ]['code'] !== T_OPEN_PARENTHESIS
		) {
			return;
		}

		$closeParenthesis = $tokens[ $openParenthesis ]['parenthesis_closer'] ?? null;

		if ($closeParenthesis === null) {
			return;
		}

		$openLine = $tokens[ $openParenthesis ]['line'];
		$closeLine = $tokens[ $closeParenthesis ]['line'];

		if ($openLine === $closeLine) {
			return;
		}

		$processedLines = [];

		for ($ptr = $openParenthesis + 1; $ptr <= $closeParenthesis; $ptr++) {
			$line = $tokens[ $ptr ]['line'];

			if (
				$line <= $openLine
				|| isset($processedLines[ $line ])
			) {
				continue;
			}

			$lineStart = $this->findLineStart($tokens, $ptr);

			$firstOnLine = $this->findNextNonWhitespace($tokens, $lineStart);

			if (
				$firstOnLine === null
				|| $tokens[ $firstOnLine ]['line'] !== $line
			) {
				$processedLines[ $line ] = true;
				continue;
			}

			$phpcsFile->fixer->addContentBefore($firstOnLine, $indentUnit);

			$processedLines[ $line ] = true;
		}
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $semicolon
	 * @param int $lastCallEnd
	 * @return void
	 */
	protected function fixSemicolon(File $phpcsFile, int $semicolon, int $lastCallEnd): void {
		$tokens = $phpcsFile->getTokens();

		$phpcsFile->fixer->beginChangeset();

		/*
		 * Remove whitespace between the closing ')' and ';'.
		 */
		for ($ptr = $lastCallEnd + 1; $ptr < $semicolon; $ptr++) {
			if ($tokens[ $ptr ]['code'] === T_WHITESPACE) {
				$phpcsFile->fixer->replaceToken($ptr, '');
			}
		}

		/*
		 * Keep the semicolon on the next line.
		 */
		$phpcsFile->fixer->addContentBefore($semicolon, PHP_EOL);

		$phpcsFile->fixer->endChangeset();
	}


	/**
	 * Determines whether a token can represent a method name.
	 *
	 * @param string|int $code
	 * @return bool
	 */
	protected function isMethodNameToken(string|int $code): bool {
		return in_array(
			$code,
			[
				T_STRING,
				T_VARIABLE,
				T_NAME_QUALIFIED,
				T_NAME_FULLY_QUALIFIED,
				'T_STRING',
				'T_VARIABLE',
				'T_NAME_QUALIFIED',
				'T_NAME_FULLY_QUALIFIED',
			],
			true
		);
	}


	/**
	 * Determines whether a token is an object operator.
	 *
	 * @param string|int $code
	 * @return bool
	 */
	protected function isObjectOperator(string|int $code): bool {
		if (
			$code === T_OBJECT_OPERATOR
			|| $code === 'T_OBJECT_OPERATOR'
		) {
			return true;
		}

		return self::NULLSAFE_OBJECT_OPERATOR !== null
			&& (
				$code === self::NULLSAFE_OBJECT_OPERATOR
				|| $code === 'T_NULLSAFE_OBJECT_OPERATOR'
			);
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
	 * Finds the previous code token and ignores whitespace/comments.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int|null
	 */
	protected function findPreviousCodeToken(array $tokens, int $ptr): ?int {
		while ($ptr >= 0) {
			if (!$this->isIgnorableIndentReferenceToken($tokens[ $ptr ])) {
				return $ptr;
			}

			$ptr--;
		}

		return null;
	}


	/**
	 * Finds the next code token and ignores whitespace/comments.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int|null
	 */
	protected function findNextCodeToken(array $tokens, int $ptr): ?int {
		$count = count($tokens);

		while ($ptr < $count) {
			if (!$this->isIgnorableIndentReferenceToken($tokens[ $ptr ])) {
				return $ptr;
			}

			$ptr++;
		}

		return null;
	}


	/**
	 * @param array<string, mixed> $token
	 * @return bool
	 */
	protected function isIgnorableIndentReferenceToken(array $token): bool {
		if ($token['code'] === T_WHITESPACE || $token['code'] === T_COMMENT) {
			return true;
		}

		return str_starts_with((string)($token['type'] ?? ''), 'T_DOC_COMMENT');
	}


	/**
	 * Finds the next non-whitespace token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int|null
	 */
	protected function findNextNonWhitespace(array $tokens, int $ptr): ?int {
		$count = count($tokens);

		while ($ptr < $count) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return $ptr;
			}

			$ptr++;
		}

		return null;
	}


	/**
	 * Returns the first token on the current line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int
	 */
	protected function findLineStart(array $tokens, int $ptr): int {
		$line = $tokens[ $ptr ]['line'];

		while (
			$ptr > 0
			&& $tokens[ $ptr - 1 ]['line'] === $line
		) {
			$ptr--;
		}

		return $ptr;
	}


	/**
	 * Returns the leading whitespace of the current line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return string
	 */
	protected function getLineIndent(array $tokens, int $ptr): string {
		$lineStart = $this->findLineStart($tokens, $ptr);

		$indent = '';

		for ($current = $lineStart; $current < $ptr; $current++) {
			if ($tokens[ $current ]['code'] !== T_WHITESPACE) {
				break;
			}

			$indent .= $tokens[ $current ]['content'];
		}

		return $indent;
	}


	/**
	 * Returns the token line number.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int
	 */
	protected function getLineNumber(array $tokens, int $ptr): int {
		return $tokens[ $ptr ]['line'];
	}


	/**
	 * Determines whether the current chain may remain on a single line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param array<int, int> $chain
	 * @param int $totalChainOperators
	 * @return bool
	 */
	protected function canStayInline(array $tokens, array $chain, int $totalChainOperators): bool {
		if ($totalChainOperators > self::MAX_INLINE_CHAIN_OPERATORS) {
			return false;
		}

		if (!$this->isSingleLineChain($tokens, $chain)) {
			return false;
		}

		$lineLength = $this->getLineLength($tokens, $chain[0]);

		return $lineLength <= self::MAX_INLINE_CHAIN_LENGTH;
	}


	/**
	 * Returns a concrete reason why an inline chain is not allowed.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param array<int, int> $chain
	 * @param int $totalChainOperators
	 * @return string|null
	 */
	protected function getInlineViolationMessage(array $tokens, array $chain, int $totalChainOperators): ?string {
		if (!$this->isSingleLineChain($tokens, $chain)) {
			return null;
		}

		if ($totalChainOperators > self::MAX_INLINE_CHAIN_OPERATORS) {
			return 'Inline chaining of method calls is only allowed for at most '
				. self::MAX_INLINE_CHAIN_OPERATORS
				. ' chain operators ("->", "?->", and optional "::").'
			;
		}

		$lineLength = $this->getLineLength($tokens, $chain[0]);

		if ($lineLength > self::MAX_INLINE_CHAIN_LENGTH) {
			return 'Inline chaining of method calls is only allowed up to '
				. self::MAX_INLINE_CHAIN_LENGTH
				. ' characters per full line including indentation.'
			;
		}

		return null;
	}


	/**
	 * Checks whether all method calls of a chain are on one line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param array<int, int> $chain
	 * @return bool
	 */
	protected function isSingleLineChain(array $tokens, array $chain): bool {
		if ($chain === []) {
			return false;
		}

		$line = $tokens[ $chain[0] ]['line'];

		foreach ($chain as $operator) {
			if ($tokens[ $operator ]['line'] !== $line) {
				return false;
			}

			$callEnd = $this->getMethodCallEnd($tokens, $operator);

			if (
				$callEnd === null
				|| $tokens[ $callEnd ]['line'] !== $line
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Returns the character length of the full line (without EOL).
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return int
	 */
	protected function getLineLength(array $tokens, int $ptr): int {
		$line = $tokens[ $ptr ]['line'];
		$lineStart = $this->findLineStart($tokens, $ptr);

		$length = 0;
		$count = count($tokens);

		for ($current = $lineStart; $current < $count && $tokens[ $current ]['line'] === $line; $current++) {
			$content = (string)$tokens[ $current ]['content'];
			$length += strcspn($content, "\r\n");
		}

		return $length;
	}


	/**
	 * Converts indentation text to visual width using tab width.
	 *
	 * @param string $indent
	 * @param int $tabWidth
	 * @return int
	 */
	protected function getIndentWidth(string $indent, int $tabWidth): int {
		$width = 0;

		foreach (str_split($indent) as $char) {
			if ($char === "\t") {
				$width += $tabWidth;
				continue;
			}

			if ($char === ' ') {
				$width++;
			}
		}

		return $width;
	}
}
