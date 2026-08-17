<?php declare(strict_types=1);


namespace Janus\Tests\Functions;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class ArrowFunctionDeclarationUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			10 => 1, // Space after fn
			13 => 1, // No space before =>
			16 => 1, // No space after =>
			19 => 3, // Multiple issues
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
