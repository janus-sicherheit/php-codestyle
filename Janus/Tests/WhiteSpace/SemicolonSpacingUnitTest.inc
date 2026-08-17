<?php declare(strict_types=1);

class ChainHelper {
	public function first(): self {
		return $this;
	}


	public function second(): self {
		return $this;
	}
}

// Valid: No space before semicolon
$value = 1;

// Valid: Single-line method chain
$chain = new ChainHelper();
$chain->first()->second();

// Valid: Multiline method chain with semicolon on following line
$chain
	->first()
	->second()
;

// Valid: Multiline concatenation with semicolon on following line
$text = 'hello'
	. ' world'
;

// Valid: Standalone closing parenthesis with semicolon
function validParenthesis(string $value): string {
	return trim(
		$value
	);
}


// Valid: Standalone closing parenthesis on separate line from semicolon
function validParenthesisSplit(string $value): string {
	return trim(
		$value
	);
}


// Invalid: Space before semicolon
$value = 1 ;

// Invalid: Single-line chain with semicolon split
$chain->first()->second()
;

// Invalid: Multiline chain with semicolon on same line
$chain
	->first()
	->second();

// Invalid: Multiline concatenation with semicolon on same line
$text = 'hello'
	. ' world';
