<?php declare(strict_types=1);


namespace Janus\Sniffs\Classes;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


/**
 * Enforces blank line spacing between class members.
 *
 * Constants, static properties, and regular properties each form their own group. Consecutive members within the same group must
 * have no blank line between them. Members of different groups must be separated by exactly two blank lines. Methods may be
 * separated by one or two blank lines. A doc comment and/or PHP attributes directly preceding a member are treated as part of
 * that member when measuring spacing.
 */
class ClassMemberSpacingSniff implements Sniff {
	/**
	 * Identifies a member group consisting of constants.
	 */
	protected const string GROUP_CONSTANT = 'constant';
	/**
	 * Identifies a member group consisting of methods.
	 */
	protected const string GROUP_METHOD = 'method';
	/**
	 * Identifies a member group consisting of non-static properties.
	 */
	protected const string GROUP_PROPERTY = 'property';
	/**
	 * Identifies a member group consisting of static properties.
	 */
	protected const string GROUP_STATIC_PROPERTY = 'static_property';
	/**
	 * Tokens that mark the start of a member declaration.
	 * Anything between a member's declaration and the previous
	 * member's end is considered part of the previous member.
	 */
	protected const array MEMBER_START_BOUNDARY_TOKENS = [
		T_SEMICOLON,
		T_OPEN_CURLY_BRACKET,
		T_CLOSE_CURLY_BRACKET,
		T_OPEN_PARENTHESIS,
		T_COMMA,
	];
	/**
	 * Tokens that may appear between a member's doc comment and its declaration.
	 * These tokens are skipped over when determining the start of a member, but they do not extend the start position.
	 */
	protected const array MODIFIER_TOKENS = [
		T_PUBLIC,
		T_PROTECTED,
		T_PRIVATE,
		T_STATIC,
		T_ABSTRACT,
		T_FINAL,
		T_VAR,
		T_READONLY,
	];


	/**
	 * Registers the tokens this sniff listens for.
	 *
	 * @return array<int, int|string>
	 */
	public function register(): array {
		return [
			T_CLASS,
			T_ANON_CLASS,
			T_ENUM,
			T_INTERFACE,
			T_TRAIT,
		];
	}


	/**
	 * Checks the spacing between all members of a class-like structure.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
	 * @param int $stackPtr Pointer to the class/interface/enum/anon-class token.
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		if (!isset($tokens[ $stackPtr ]['scope_opener'])) {
			return;
		}

		$classStart = $tokens[ $stackPtr ]['scope_opener'];
		$classEnd = $tokens[ $stackPtr ]['scope_closer'];

		$members = $this->getMembers($tokens, $classStart, $classEnd);

		if (count($members) < 2) {
			return;
		}

		for ($i = 1, $count = count($members); $i < $count; $i++) {
			$previous = $members[ $i - 1 ];
			$current = $members[ $i ];

			$this->checkSpacing($phpcsFile, $previous, $current);
		}
	}


	/**
	 * Collects all constants, properties, and methods of a class body.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $classStart
	 * @param int $classEnd
	 * @return array<int, array{start: int, declaration: int, group: string}>
	 */
	protected function getMembers(array $tokens, int $classStart, int $classEnd): array {
		$members = [];

		$ptr = $classStart + 1;

		while ($ptr < $classEnd) {
			$group = $this->getMemberGroup($tokens, $ptr, $classEnd);

			if ($group === null) {
				$ptr++;

				continue;
			}

			$memberStart = $this->findMemberStart($tokens, $ptr, $classStart);

			$members[] = [
				'start' => $memberStart,
				'declaration' => $ptr,
				'group' => $group,
			];

			$ptr = $this->getMemberEnd($tokens, $ptr, $classEnd);

			$ptr++;
		}

		return $members;
	}


	/**
	 * Walks backwards from a member's declaration to include the full declaration prefix (modifiers + types)
	 * as well as directly attached doc comment and/or attribute blocks.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $declarationPtr
	 * @param int $classStart
	 * @return int
	 */
	protected function findMemberStart(array $tokens, int $declarationPtr, int $classStart): int {
		$ptr = $declarationPtr - 1;
		$start = $declarationPtr;

		while ($ptr > $classStart) {
			$code = $tokens[ $ptr ]['code'];

			if ($code === T_WHITESPACE) {
				$ptr--;

				continue;
			}

			if (in_array($code, self::MEMBER_START_BOUNDARY_TOKENS, true)) {
				break;
			}

			/*
			 * Doc comment, e.g. /** ... *\/.
			 * Jump over the whole block via its opener and keep
			 * looking backwards, in case an attribute precedes it.
			 */
			if ($code === T_DOC_COMMENT_CLOSE_TAG) {
				$opener = $tokens[ $ptr ]['comment_opener'] ?? null;

				if ($opener === null) {
					break;
				}

				$start = $opener;
				$ptr = $opener - 1;

				continue;
			}

			/*
			 * PHP attribute, e.g. #[SomeAttribute].
			 * Jump over the whole attribute block and keep looking
			 * backwards, in case a doc comment or another attribute
			 * precedes it.
			 */
			if ($code === T_ATTRIBUTE_END) {
				$opener = $tokens[ $ptr ]['attribute_opener'] ?? null;

				if ($opener === null) {
					break;
				}

				$start = $opener;
				$ptr = $opener - 1;

				continue;
			}

			/*
			 * Anything else here is part of the declaration prefix
			 * (e.g. `public`, `static`, `?int`, `Foo|Bar`,
			 * `private(set)`) and must be included in the member
			 * start to avoid collapsing tokens during fixes.
			 */
			$start = $ptr;
			$ptr--;
		}

		return $start;
	}


	/**
	 * Determines which member group a token belongs to, if any.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @param int $classEnd
	 * @return string|null
	 * @noinspection PhpUnusedParameterInspection
	 */
	protected function getMemberGroup(array $tokens, int $ptr, int $classEnd): ?string {
		$token = $tokens[ $ptr ]['code'];

		/*
		 * const
		 */
		if ($token === T_CONST) {
			return self::GROUP_CONSTANT;
		}

		/*
		 * function
		 */
		if ($token === T_FUNCTION) {
			return self::GROUP_METHOD;
		}

		/*
		 * Property declarations.
		 *
		 * We only consider a T_VARIABLE a property when it is
		 * preceded by a visibility/static/var/readonly modifier
		 * within the current declaration (possibly separated from
		 * the variable by a type declaration).
		 */
		if ($token === T_VARIABLE) {
			if (!$this->isPropertyDeclaration($tokens, $ptr)) {
				return null;
			}

			if ($this->isStaticProperty($tokens, $ptr)) {
				return self::GROUP_STATIC_PROPERTY;
			}

			return self::GROUP_PROPERTY;
		}

		return null;
	}


	/**
	 * Checks whether a T_VARIABLE token is a property declaration by
	 * scanning backwards past any type declaration for a modifier.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $variablePtr
	 * @return bool
	 */
	protected function isPropertyDeclaration(array $tokens, int $variablePtr): bool {
		$ptr = $variablePtr - 1;

		while (isset($tokens[ $ptr ])) {
			$code = $tokens[ $ptr ]['code'];

			if ($code === T_WHITESPACE) {
				$ptr--;

				continue;
			}

			if (in_array($code, self::MODIFIER_TOKENS, true)) {
				return true;
			}

			if (in_array($code, self::MEMBER_START_BOUNDARY_TOKENS, true)) {
				return false;
			}

			/*
			 * Anything else is assumed to be part of the type
			 * declaration (e.g. `?int`, `mixed`, `Foo|Bar`,
			 * `\Fully\Qualified\Name`) — keep looking backwards.
			 */
			$ptr--;
		}

		return false;
	}


	/**
	 * Checks whether a property declaration is static.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $variablePtr
	 * @return bool
	 */
	protected function isStaticProperty(array $tokens, int $variablePtr): bool {
		$ptr = $variablePtr - 1;

		while (isset($tokens[ $ptr ])) {
			if ($tokens[ $ptr ]['code'] === T_STATIC) {
				return true;
			}

			if (
				in_array(
					$tokens[ $ptr ]['code'],
					[
						T_SEMICOLON,
						T_OPEN_CURLY_BRACKET,
						T_CLOSE_CURLY_BRACKET,
					],
					true
				)
			) {
				return false;
			}

			$ptr--;
		}

		return false;
	}


	/**
	 * Finds the end of a member declaration (closing brace for
	 * methods, terminating semicolon for constants/properties).
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $start Pointer to the member's declaration keyword.
	 * @param int $classEnd
	 * @return int
	 */
	protected function getMemberEnd(array $tokens, int $start, int $classEnd): int {
		/*
		 * Method.
		 */
		if ($tokens[ $start ]['code'] === T_FUNCTION) {
			$openBrace = $tokens[ $start ]['scope_opener'] ?? null;

			if ($openBrace !== null) {
				return $tokens[ $start ]['scope_closer'];
			}
		}

		/*
		 * Constant/property declaration.
		 *
		 * Find its terminating semicolon.
		 */
		for ($ptr = $start; $ptr < $classEnd; $ptr++) {
			if ($tokens[ $ptr ]['code'] === T_SEMICOLON) {
				return $ptr;
			}
		}

		return $start;
	}


	/**
	 * Verifies and, if fixable, corrects the blank line count
	 * between two consecutive members.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param array{start: int, declaration: int, group: string} $previous
	 * @param array{start: int, declaration: int, group: string} $current
	 * @return void
	 */
	protected function checkSpacing(File $phpcsFile, array $previous, array $current): void {
		$tokens = $phpcsFile->getTokens();

		$previousEnd = $this->getMemberEnd(
			$tokens,
			$previous['declaration'],
			count($tokens)
		);

		$currentStart = $current['start'];

		$actualBlankLines = $this->getBlankLinesBetween($tokens, $previousEnd, $currentStart);

		/*
		 * Between methods:
		 *
		 * 1 or 2 blank lines are allowed.
		 */
		if (
			$previous['group'] === self::GROUP_METHOD
			&& $current['group'] === self::GROUP_METHOD
		) {
			if (
				$actualBlankLines >= 1
				&& $actualBlankLines <= 2
			) {
				return;
			}

			$requiredBlankLines = 1;
			$message = 'There must be 1 or 2 blank lines between methods. Found %d.';
		}
		elseif ($previous['group'] === $current['group']) {
			/*
			 * Members within the same non-method group (constants,
			 * static properties, properties) must have no blank
			 * lines between them.
			 */
			if ($actualBlankLines === 0) {
				return;
			}

			$requiredBlankLines = 0;
			$message = 'There must be no blank lines between members of the same group. Found %d.';
		}
		else {
			/*
			 * Different groups require exactly 2 blank lines.
			 */
			if ($actualBlankLines === 2) {
				return;
			}

			$requiredBlankLines = 2;
			$message = 'There must be exactly 2 blank lines between different member groups. Found %d.';
		}

		$fix = $phpcsFile->addFixableError($message, $currentStart, 'Spacing', [$actualBlankLines]);

		if (!$fix) {
			return;
		}

		$this->fixSpacing($phpcsFile, $previousEnd, $currentStart, $requiredBlankLines);
	}


	/**
	 * Counts the blank lines between the end of one member and the
	 * start of the next.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $previousEnd
	 * @param int $currentStart
	 * @return int
	 */
	protected function getBlankLinesBetween(array $tokens, int $previousEnd, int $currentStart): int {
		$previousLine = $tokens[ $previousEnd ]['line'];
		$currentLine = $tokens[ $currentStart ]['line'];

		return max(0, $currentLine - $previousLine - 1);
	}


	/**
	 * Rewrites the whitespace between two members to contain the
	 * required number of blank lines.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $previousEnd
	 * @param int $currentStart
	 * @param int $blankLines
	 * @return void
	 */
	protected function fixSpacing(File $phpcsFile, int $previousEnd, int $currentStart, int $blankLines): void {
		$tokens = $phpcsFile->getTokens();
		$indentation = $this->getIndentationBeforeToken($tokens, $currentStart);

		$phpcsFile->fixer->beginChangeset();

		/*
		 * Remove everything between the two members.
		 *
		 * This is intentionally limited to whitespace.
		 * Comments are not removed.
		 */
		for ($ptr = $previousEnd + 1; $ptr < $currentStart; $ptr++) {
			if ($tokens[ $ptr ]['code'] === T_WHITESPACE) {
				$phpcsFile->fixer->replaceToken($ptr, '');
			}
		}

		/*
		 * Insert the required line breaks immediately
		 * before the next member.
		 */
		$phpcsFile->fixer->addContentBefore(
			$currentStart,
			str_repeat(PHP_EOL, $blankLines + 1) . $indentation
		);

		$phpcsFile->fixer->endChangeset();
	}


	/**
	 * Extracts the indentation prefix directly preceding a token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $tokenPtr
	 * @return string
	 */
	protected function getIndentationBeforeToken(array $tokens, int $tokenPtr): string {
		$previousPtr = $tokenPtr - 1;

		if (
			$previousPtr < 0
			|| !isset($tokens[ $previousPtr ])
			|| $tokens[ $previousPtr ]['code'] !== T_WHITESPACE
		) {
			return '';
		}

		$content = $tokens[ $previousPtr ]['content'];
		$lineFeedPos = strrpos($content, "\n");

		if ($lineFeedPos === false) {
			$carriageReturnPos = strrpos($content, "\r");

			if ($carriageReturnPos === false) {
				return $content;
			}

			return substr($content, $carriageReturnPos + 1);
		}

		return substr($content, $lineFeedPos + 1);
	}
}
