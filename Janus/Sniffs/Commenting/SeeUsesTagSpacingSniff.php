<?php declare(strict_types=1);


namespace Janus\Sniffs\Commenting;


use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;


/**
 * Ensures that there is exactly one single space after `@see` and `@uses` tags in doc comments.
 * This sniff will fix a bug in PhpStorm where the IDE aligns the `@see` and `@uses` tags
 * with `@noinspection` tags.
 */
class SeeUsesTagSpacingSniff implements Sniff {
	/**
	 * @var array<int, string>
	 */
	protected const array TAGS = [
		'@see',
		'@uses',
	];


	/**
	 * @return array<int, int|string>
	 */
	public function register(): array {
		return [
			T_DOC_COMMENT_TAG,
		];
	}


	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $stackPtr
	 * @return void
	 */
	public function process(File $phpcsFile, int $stackPtr): void {
		$tokens = $phpcsFile->getTokens();
		$tag = strtolower($tokens[ $stackPtr ]['content']);

		if (!in_array($tag, self::TAGS, true)) {
			return;
		}

		$nextPtr = $stackPtr + 1;

		if (!isset($tokens[ $nextPtr ])) {
			return;
		}

		$nextToken = $tokens[ $nextPtr ];

		if ($nextToken['code'] !== T_DOC_COMMENT_WHITESPACE) {
			$fix = $phpcsFile->addFixableError(
				sprintf('There must be exactly one single space after %s.', $tag),
				$stackPtr,
				'SpacingAfterTag'
			);

			if ($fix) {
				$phpcsFile->fixer->addContent($stackPtr, ' ');
			}

			return;
		}

		if ($nextToken['content'] === ' ') {
			return;
		}

		$fix = $phpcsFile->addFixableError(
			sprintf('There must be exactly one single space after %s.', $tag),
			$nextPtr,
			'SpacingAfterTag'
		);

		if (!$fix) {
			return;
		}

		$phpcsFile->fixer->beginChangeset();
		$phpcsFile->fixer->replaceToken($nextPtr, ' ');

		/*
		 * Collapse additional same-line whitespace tokens after the tag.
		 */
		$cleanupPtr = $nextPtr + 1;
		$tagLine = $tokens[ $stackPtr ]['line'];

		while (
			isset($tokens[ $cleanupPtr ])
			&& $tokens[ $cleanupPtr ]['code'] === T_DOC_COMMENT_WHITESPACE
			&& $tokens[ $cleanupPtr ]['line'] === $tagLine
		) {
			$phpcsFile->fixer->replaceToken($cleanupPtr, '');

			$cleanupPtr++;
		}

		$phpcsFile->fixer->endChangeset();
	}
}
