<?php declare(strict_types=1);

// Valid: Single space between type and constant name
class ValidConstantSpacing {
	public const int A = 1, B = 2;
	public const ?Foo BAR = null;
	public const string NAME = 'x';
}

// Invalid: Multiple spaces between type and constant name
class InvalidConstantSpacing {
	public const string  NAME = 'x';
}

// Invalid: Tab between type and constant name
class InvalidConstantTab {
	public const ?Foo    NAME = null;
}

// Invalid: Multiple spaces after comma in multi-const
class InvalidMultiConstSpacing {
	public const int A = 1,  B = 2;
}
