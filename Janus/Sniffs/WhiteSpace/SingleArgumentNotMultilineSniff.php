<?php declare(strict_types=1);


namespace Janus\Sniffs\WhiteSpace;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;


/**
 * Flags method-/function calls with a single simple argument that is
 * spread across multiple lines, even though the collapsed call would
 * still fit within the configured line length limit.
 */
class SingleArgumentNotMultilineSniff implements Sniff {
	/**
	 * Token codes that count as a "simple" value.
	 *
	 * @var array<int>
	 */
	protected const array SIMPLE_CODES = [
		T_LNUMBER,
		T_DNUMBER,
		T_CONSTANT_ENCAPSED_STRING,
		T_VARIABLE,
		T_STRING,
	];


	/**
	 * Line length limit to check against, matches Generic.Files.LineLength
	 * by default but can be overridden per ruleset.
	 *
	 * @var int
	 */
	public int $lineLimit = 140;


	/**
	 * @return array<int>
	 */
	public function register(): array {
		return [T_STRING];
	}


	/**
	 * @param File $phpcsFile
	 * @param int $stackPtr
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		// Ignore function declarations ("function foo(...)").
		$prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, null, true);
		if ($prev !== false && $tokens[ $prev ]['code'] === T_FUNCTION) {
			return;
		}

		$openParen = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, null, true);
		if ($openParen === false || $tokens[ $openParen ]['code'] !== T_OPEN_PARENTHESIS) {
			return;
		}

		if (!isset($tokens[ $openParen ]['parenthesis_closer'])) {
			return;
		}

		$closeParen = $tokens[ $openParen ]['parenthesis_closer'];

		// Nothing to do if the call is already single-line.
		if ($tokens[ $openParen ]['line'] === $tokens[ $closeParen ]['line']) {
			return;
		}

		$argStart = $phpcsFile->findNext(Tokens::$emptyTokens, $openParen + 1, $closeParen, true);
		if ($argStart === false) {
			// Empty call, e.g. foo(\n).
			return;
		}

		$argEnd = $phpcsFile->findPrevious(Tokens::$emptyTokens, $closeParen - 1, $openParen, true);

		for ($i = $argStart; $i <= $argEnd; $i++) {
			// A comma on this level means more than one argument - out of scope.
			if ($tokens[ $i ]['code'] === T_COMMA) {
				return;
			}

			if (in_array($tokens[ $i ]['code'], Tokens::$emptyTokens, true)) {
				continue;
			}

			if (!in_array($tokens[ $i ]['code'], self::SIMPLE_CODES, true)) {
				// Nested call, array, concatenation, etc. - not "simple".
				return;
			}
		}

		$argText = trim($phpcsFile->getTokensAsString($argStart, $argEnd - $argStart + 1));

		// Simulate: what would the line look like if the argument sat
		// directly after the already-existing opening parenthesis?
		$collapsedLength = $tokens[ $openParen ]['column'] - 1
			+ 1 // '('
			+ strlen($argText)
			+ 1; // ')'

		if ($collapsedLength > $this->lineLimit) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'A single simple argument must not be split across multiple lines unless the %s character line limit would be exceeded',
			$stackPtr,
			'SingleArgumentSplit',
			[$this->lineLimit]
		);

		if ($fix !== true) {
			return;
		}

		$phpcsFile->fixer->beginChangeset();

		// Remove whitespace/newlines between the opening parenthesis and the argument.
		for ($i = $openParen + 1; $i < $argStart; $i++) {
			$phpcsFile->fixer->replaceToken($i, '');
		}

		// Remove whitespace/newlines between the argument and the closing parenthesis.
		for ($i = $argEnd + 1; $i < $closeParen; $i++) {
			$phpcsFile->fixer->replaceToken($i, '');
		}

		$phpcsFile->fixer->endChangeset();
	}
}
