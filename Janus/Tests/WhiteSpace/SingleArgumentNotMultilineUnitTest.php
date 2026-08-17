<?php declare(strict_types=1);


namespace Janus\Tests\WhiteSpace;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class SingleArgumentNotMultilineUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			14 => 1, // Single argument unnecessarily split
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
