<?php declare(strict_types=1);

// Valid: Property with @var tag
class ValidVariableComment {
	/** @var string */
	private string $name = 'demo';
}

// Valid: Property with @inheritdoc (allowed without @var)
class ValidInheritdoc {
	/** @inheritdoc */
	private string $name = 'demo';
}

// Invalid: Property with @see but no @var
class InvalidVariableComment {
	/** @see self::helper */
	private string $name = 'demo';


	private static function helper(): void {
	}
}
