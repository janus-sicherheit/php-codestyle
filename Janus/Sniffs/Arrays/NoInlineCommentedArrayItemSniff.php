<?php declare(strict_types=1);


namespace Janus\Sniffs\Arrays;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


class NoInlineCommentedArrayItemSniff implements Sniff {
	/**
	 * Registers the tokens this sniff listens for.
	 *
	 * @return array<int>
	 */
	public function register(): array {
		return [
			T_COMMENT,
		];
	}


	/**
	 * Disallows comments on the same line as an array item.
	 *
	 * @param File $phpcsFile
	 * @param int $stackPtr
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();

		if (!$this->isCommentToken($tokens[ $stackPtr ])) {
			return;
		}

		if (!$this->isInsideArray($tokens, $stackPtr)) {
			return;
		}

		$line = $tokens[ $stackPtr ]['line'];
		$lineStart = $stackPtr;
		$lineEnd = $stackPtr;

		while ($lineStart > 0 && $tokens[ $lineStart - 1 ]['line'] === $line) {
			$lineStart--;
		}

		$lastIndex = count($tokens) - 1;
		while ($lineEnd < $lastIndex && $tokens[ $lineEnd + 1 ]['line'] === $line) {
			$lineEnd++;
		}

		$commentRange = $this->getCommentRange($tokens, $stackPtr);

		$hasCodeBeforeComment = $this->hasNonWhitespaceOnLineBefore(
			$tokens,
			$lineStart,
			$commentRange['start']
		);

		$hasCodeAfterComment = false;

		for ($ptr = $commentRange['end'] + 1; $ptr <= $lineEnd; $ptr++) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				$hasCodeAfterComment = true;
				break;
			}
		}

		if (!$hasCodeBeforeComment && !$hasCodeAfterComment) {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			'Array items must not share a line with comments.',
			$commentRange['start'],
			'InlineCommentedArrayItem'
		);

		if (!$fix) {
			return;
		}

		$this->fixInlineCommentedArrayItem(
			$phpcsFile,
			$lineStart,
			$lineEnd,
			$commentRange['start'],
			$commentRange['end']
		);
	}


	/**
	 * Moves an inline array item comment to its own line above the item.
	 *
	 * @param File $phpcsFile
	 * @param int $lineStart
	 * @param int $lineEnd
	 * @param int $commentStart
	 * @param int $commentEnd
	 * @return void
	 */
	protected function fixInlineCommentedArrayItem(
		File $phpcsFile,
		int $lineStart,
		int $lineEnd,
		int $commentStart,
		int $commentEnd
	): void {
		$tokens = $phpcsFile->getTokens();
		$indentation = $this->getLineIndentation($tokens, $lineStart, $lineEnd);
		$prefixAtInsertion = $this->getInlinePrefixBeforeToken($tokens, $lineStart);

		$commentContent = '';
		for ($ptr = $commentStart; $ptr <= $commentEnd; $ptr++) {
			$commentContent .= $tokens[ $ptr ]['content'];
		}

		$phpcsFile->fixer->beginChangeset();

		/*
		 * Remove one same-line spacing token around the comment
		 * so the remaining array item keeps clean spacing.
		 */
		$removeBefore = $commentStart - 1;
		$hasCodeBeforeComment = $this->hasNonWhitespaceOnLineBefore(
			$tokens,
			$lineStart,
			$commentStart
		);

		if (
			$hasCodeBeforeComment
			&& $removeBefore >= $lineStart
			&& $tokens[ $removeBefore ]['code'] === T_WHITESPACE
			&& !str_contains($tokens[ $removeBefore ]['content'], "\n")
			&& !str_contains($tokens[ $removeBefore ]['content'], "\r")
		) {
			$phpcsFile->fixer->replaceToken($removeBefore, '');
		}

		$removeAfter = $commentEnd + 1;
		if (
			$removeAfter <= $lineEnd
			&& isset($tokens[ $removeAfter ])
			&& $tokens[ $removeAfter ]['code'] === T_WHITESPACE
			&& !str_contains($tokens[ $removeAfter ]['content'], "\n")
			&& !str_contains($tokens[ $removeAfter ]['content'], "\r")
		) {
			$phpcsFile->fixer->replaceToken($removeAfter, '');
		}

		for ($ptr = $commentStart; $ptr <= $commentEnd; $ptr++) {
			$phpcsFile->fixer->replaceToken($ptr, '');
		}

		$phpcsFile->fixer->addContentBefore(
			$lineStart,
			($prefixAtInsertion === '' ? $indentation : '') . $commentContent . PHP_EOL
		);

		$phpcsFile->fixer->endChangeset();
	}


	/**
	 * Checks whether the token is located inside an array construct.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $ptr
	 * @return bool
	 */
	protected function isInsideArray(array $tokens, int $ptr): bool {
		$shortArrayDepth = 0;
		$parenthesisDepth = 0;

		for ($scan = $ptr - 1; $scan >= 0; $scan--) {
			$code = $tokens[ $scan ]['code'];

			if ($code === T_CLOSE_SHORT_ARRAY) {
				$shortArrayDepth++;

				continue;
			}

			if ($code === T_OPEN_SHORT_ARRAY) {
				if ($shortArrayDepth === 0) {
					return true;
				}

				$shortArrayDepth--;

				continue;
			}

			if ($code === T_CLOSE_PARENTHESIS) {
				$parenthesisDepth++;

				continue;
			}

			if ($code === T_OPEN_PARENTHESIS) {
				if ($parenthesisDepth > 0) {
					$parenthesisDepth--;

					continue;
				}

				$owner = $tokens[ $scan ]['parenthesis_owner'] ?? null;
				if ($owner !== null && $tokens[ $owner ]['code'] === T_ARRAY) {
					return true;
				}

				return false;
			}
		}

		return false;
	}


	/**
	 * Checks whether a token is any kind of comment token.
	 *
	 * @param array<string, mixed> $token
	 * @return bool
	 */
	protected function isCommentToken(array $token): bool {
		if ($token['code'] === T_COMMENT) {
			return true;
		}

		return str_starts_with((string)$token['type'], 'T_DOC_COMMENT');
	}


	/**
	 * Returns comment start/end token pointers for a comment token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $commentPtr
	 * @return array{start: int, end: int}
	 */
	protected function getCommentRange(array $tokens, int $commentPtr): array {
		$token = $tokens[ $commentPtr ];

		if ($token['code'] === T_COMMENT) {
			return [
				'start' => $commentPtr,
				'end' => $commentPtr,
			];
		}

		$start = $token['comment_opener'] ?? $commentPtr;
		$end = $tokens[ $start ]['comment_closer'] ?? $commentPtr;

		return [
			'start' => $start,
			'end' => $end,
		];
	}


	/**
	 * Extracts indentation at the beginning of a physical line.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $lineStart
	 * @param int $lineEnd
	 * @return string
	 */
	protected function getLineIndentation(array $tokens, int $lineStart, int $lineEnd): string {
		$indentation = '';

		for ($ptr = $lineStart; $ptr <= $lineEnd; $ptr++) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				break;
			}

			$content = $tokens[ $ptr ]['content'];
			if (str_contains($content, "\n") || str_contains($content, "\r")) {
				continue;
			}

			$indentation .= $content;
		}

		return $indentation;
	}


	/**
	 * Returns the current-line prefix right before a token.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $tokenPtr
	 * @return string
	 */
	protected function getInlinePrefixBeforeToken(array $tokens, int $tokenPtr): string {
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


	/**
	 * Checks whether a line contains non-whitespace before a pointer.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @param int $lineStart
	 * @param int $beforePtr
	 * @return bool
	 */
	protected function hasNonWhitespaceOnLineBefore(array $tokens, int $lineStart, int $beforePtr): bool {
		for ($ptr = $lineStart; $ptr < $beforePtr; $ptr++) {
			if ($tokens[ $ptr ]['code'] !== T_WHITESPACE) {
				return true;
			}
		}

		return false;
	}
}
