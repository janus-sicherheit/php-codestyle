<?php declare(strict_types=1);


namespace Janus\Tests\Arrays;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class NoInlineCommentedArrayItemUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			22 => 1, // /* 2 */ 3
			29 => 1, // 'b' => 2, /* inline comment */
			36 => 1, // /* inline comment */ 'b' => 2
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
