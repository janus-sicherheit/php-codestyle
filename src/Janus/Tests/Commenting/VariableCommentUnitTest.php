<?php declare(strict_types=1);


namespace Janus\Tests\Commenting;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class VariableCommentUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			17 => 1, // Property with @see but missing @var
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
