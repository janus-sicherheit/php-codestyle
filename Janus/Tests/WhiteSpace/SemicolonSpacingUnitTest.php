<?php declare(strict_types=1);


namespace Janus\Tests\WhiteSpace;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class SemicolonSpacingUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			49 => 1, // Space before semicolon
			53 => 1, // Single-line chain with split semicolon
			58 => 1, // Multiline chain with semicolon on same line
			62 => 1, // Multiline concat with semicolon on same line
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
