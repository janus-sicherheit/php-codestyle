<?php declare(strict_types=1);

$service = new stdClass();

// Valid: Inline chain with <=2 operators
$result = $service->prepare()->execute();

// Valid: Static start with inline chain
$result = Factory::make()->run();

// Valid: Nullsafe inline
$result = $service?->prepare()?->execute();

// Valid: Multiline chain with operators on own lines
$result = $service
	->first()
	->second()
;

// Invalid: Inline chain with >2 operators
$result = $service->prepare()->execute()->finalize()
;

// Invalid: Inline chain too long (>140 chars)
$result = $service->prepare('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->execute('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

// Invalid: Single call split before ->
$result = $service
	->run('foobar');

// Invalid: Operator not on own line in multiline chain
$result = $service
	->first()->second()
	->third()
;

// Invalid: Wrong indentation for operators
$result = $service
		->first()
	->second()
;

// Invalid: Wrong indentation for operators
$result = $service
->first()
->second()
;

// Invalid: Wrong indentation for operators
$result = $service
	->first()
->second()
;

// Invalid: Wrong indentation for operators
$result = $service
		->first()
	->second()
;

// Invalid: Semicolon not on following line (multiline)
$result = $service
	->first()
	->second();
