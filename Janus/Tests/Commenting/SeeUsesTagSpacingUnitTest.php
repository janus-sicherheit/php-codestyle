<?php declare(strict_types=1);


namespace Janus\Tests\Commenting;


use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;


final class SeeUsesTagSpacingUnitTest extends AbstractSniffTestCase {
	public function getErrorList(): array {
		return [
			21 => 1, // @see   self::demo
			29 => 1, // @uses  self::helper
		];
	}


	public function getWarningList(): array {
		return [];
	}
}
