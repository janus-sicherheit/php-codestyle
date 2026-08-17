<?php declare(strict_types=1);


namespace Janus\Tests\WhiteSpace;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class ConstantDeclarationSpacingUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			12 => 1, // Multiple spaces
			17 => 1, // Tab character
			22 => 1, // Multiple spaces after comma
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
