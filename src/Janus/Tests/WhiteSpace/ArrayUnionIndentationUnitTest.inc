<?php declare(strict_types=1);

$defaults = ['a' => 0];

// Valid: Closing bracket aligned with array start
$config = [
	'a' => 1,
] + $defaults;

// Invalid: Closing bracket misaligned
$config = [
		'a' => 1,
	] + $defaults;
