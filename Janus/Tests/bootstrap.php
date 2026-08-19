<?php declare(strict_types=1);

$root = dirname(__DIR__, 2);

// Composer autoload
require_once $root . '/vendor/autoload.php';

// PHPCS test classes are not part of Composer autoload; map them explicitly.
spl_autoload_register(static function (string $class) use ($root): void {
	$prefix = 'PHP_CodeSniffer\\Tests\\';

	if (!str_starts_with($class, $prefix)) {
		return;
	}

	$relative = substr($class, strlen($prefix));
	$path = $root . '/vendor/squizlabs/php_codesniffer/tests/' . str_replace('\\', '/', $relative) . '.php';

	if (is_file($path)) {
		require_once $path;
	}
});

// Load PHPCS test framework
$phpcsTestsBootstrap = $root . '/vendor/squizlabs/php_codesniffer/tests/bootstrap.php';

if (file_exists($phpcsTestsBootstrap)) {
	require_once $phpcsTestsBootstrap;
}



