<?php declare(strict_types=1);


namespace Janus\Tests\Operators;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class MethodChainingUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			21 => 1, // >2 operators inline
			25 => 2, // Inline too long
			29 => 1, // Single call split
			33 => 1, // Operator not on own line
			39 => 1, // Wrong indentation
			45 => 1, // Wrong indentation
			52 => 1, // Wrong indentation
			57 => 1, // Wrong indentation
			64 => 1, // Semicolon placement
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
