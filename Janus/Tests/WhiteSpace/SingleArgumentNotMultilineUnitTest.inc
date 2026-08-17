<?php declare(strict_types=1);

// Valid: Single argument on same line
trim(' value ');

// Valid: Multiple arguments can be multiline
sprintf(
	'%s %s',
	'hello',
	'world'
);

// Invalid: Single argument unnecessarily split
trim(
	' value '
);
