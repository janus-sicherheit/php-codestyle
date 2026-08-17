<?php declare(strict_types=1);

// Valid: Comment on its own line in numeric array
$values = [
	1,
	/* 2 */
	3,
	4,
];

// Valid: Comment on its own line in associative array
$map = [
	'a' => 1,
	/* middle item */
	'b' => 2,
	'c' => 3,
];

// Invalid: Inline comment with numeric item
$values = [
	1,
	/* 2 */ 3,
	4,
];

// Invalid: Inline comment after associative item
$map = [
	'a' => 1,
	'b' => 2, /* inline comment */
	'c' => 3,
];

// Invalid: Inline comment before associative item
$map = [
	'a' => 1,
	/* inline comment */'b' => 2,
	'c' => 3,
];
