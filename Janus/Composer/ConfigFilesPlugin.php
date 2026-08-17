<?php declare(strict_types=1);


namespace Janus\Composer;


use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Util\ProcessExecutor;


final class ConfigFilesPlugin implements PluginInterface, EventSubscriberInterface {
	private Composer $composer;
	private IOInterface $io;


	/**
	 * @return array<string, string>
	 */
	public static function getSubscribedEvents(): array {
		return [
			'post-install-cmd' => 'syncConfigFiles',
			'post-update-cmd' => 'syncConfigFiles',
		];
	}


	public function activate(Composer $composer, IOInterface $io): void {
		$this->composer = $composer;
		$this->io = $io;
	}


	public function deactivate(Composer $composer, IOInterface $io): void {
	}


	public function uninstall(Composer $composer, IOInterface $io): void {
	}


	public function syncConfigFiles(Event $event): void {
		$vendorDir = (string)$this->composer->getConfig()->get('vendor-dir');
		$projectRoot = dirname($vendorDir);
		$packageRoot = dirname(__DIR__, 3);

		$filesToCopy = [
			'.editorconfig',
			'code_inspection.xml',
			'code_style.xml',
			'phpcs.xml',
		];

		foreach ($filesToCopy as $file) {
			$sourcePath = $packageRoot . DIRECTORY_SEPARATOR . $file;
			$targetPath = $projectRoot . DIRECTORY_SEPARATOR . $file;

			if (!is_file($sourcePath)) {
				$this->io->writeError(sprintf('<warning>Skipped missing source file: %s</warning>', $sourcePath));
				continue;
			}

			$content = file_get_contents($sourcePath);
			if ($content === false) {
				$this->io->writeError(sprintf('<warning>Failed reading source file: %s</warning>', $sourcePath));
				continue;
			}

			if (file_put_contents($targetPath, $content) === false) {
				$this->io->writeError(sprintf('<warning>Failed writing target file: %s</warning>', $targetPath));
				continue;
			}

			$this->io->write(sprintf('<info>Copied %s to project root.</info>', $file));
		}

		$this->registerJanusStandardForPhpcs($projectRoot);
	}


	private function registerJanusStandardForPhpcs(string $projectRoot): void {
		$phpcsScript = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR
			. 'squizlabs' . DIRECTORY_SEPARATOR . 'php_codesniffer' . DIRECTORY_SEPARATOR
			. 'bin' . DIRECTORY_SEPARATOR . 'phpcs';

		if (!is_file($phpcsScript)) {
			$this->io->writeError(sprintf('<warning>Skipped PHPCS registration, executable not found: %s</warning>', $phpcsScript));

			return;
		}

		$processExecutor = new ProcessExecutor($this->io);
		$standardPath = '../../janus-sicherheit/php-codestyle';
		$installedPaths = $this->buildInstalledPaths($processExecutor, $projectRoot, $phpcsScript, $standardPath);

		$setCommand = sprintf(
			'%s %s --config-set installed_paths %s',
			escapeshellarg(PHP_BINARY),
			escapeshellarg($phpcsScript),
			escapeshellarg($installedPaths)
		);

		$output = '';
		$exitCode = $processExecutor->execute($setCommand, $output, $projectRoot);
		if ($exitCode !== 0) {
			$this->io->writeError(sprintf('<warning>Failed to set PHPCS installed_paths. Output: %s</warning>', trim($output)));

			return;
		}

		$this->io->write('<info>Registered JANUS PHPCS standard in installed_paths.</info>');
	}


	private function buildInstalledPaths(
		ProcessExecutor $processExecutor,
		string $projectRoot,
		string $phpcsScript,
		string $standardPath,
	): string {
		$showCommand = sprintf('%s %s --config-show', escapeshellarg(PHP_BINARY), escapeshellarg($phpcsScript));
		$output = '';
		$exitCode = $processExecutor->execute($showCommand, $output, $projectRoot);

		if ($exitCode !== 0 || preg_match('/^installed_paths:\s*(.+)$/m', $output, $matches) !== 1) {
			return $standardPath;
		}

		$existingPaths = array_filter(array_map('trim', explode(',', $matches[1])));
		if (!in_array($standardPath, $existingPaths, true)) {
			$existingPaths[] = $standardPath;
		}

		return implode(',', $existingPaths);
	}
}
