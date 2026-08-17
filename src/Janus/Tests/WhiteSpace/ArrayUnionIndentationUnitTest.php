<?php declare(strict_types=1);


namespace Janus\Tests\WhiteSpace;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class ArrayUnionIndentationUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			13 => 1, // Closing bracket misaligned
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
