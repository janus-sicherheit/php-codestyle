<?php declare(strict_types=1);


namespace Janus\Tests\Classes;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class ClassMemberSpacingUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			60 => 1, // Only 1 blank line between const and property (needs 2)
			67 => 1, // No blank line between methods
			78 => 1, // More than 2 blank lines between methods
			86 => 1, // Blank line within same property group
			93 => 1, // Only 1 blank line before docblock (different groups)
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
