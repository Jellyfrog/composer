<?php declare(strict_types=1);

namespace Composer\SeparatePlugins\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;

abstract class SeparatePluginsBaseCommand extends BaseCommand
{
    protected const DEFAULT_FILE = 'composer-plugins.json';

    protected function getSeparateFile(): string
    {
        return self::DEFAULT_FILE;
    }

    protected function getSeparateLockFile(): string
    {
        return Factory::getLockFile($this->getSeparateFile());
    }

    protected function ensureSeparateFileExists(IOInterface $io): bool
    {
        $file = $this->getSeparateFile();
        if (file_exists($file)) {
            return false;
        }

        $template = [
            'description' => 'Separate plugins managed by composer/separate-plugins',
            'config' => [
                'vendor-dir' => 'vendor-plugins',
                'allow-plugins' => true,
                'lock' => true,
            ],
        ];

        file_put_contents($file, JsonFile::encode($template) . "\n");
        $io->writeError('<info>Created ' . $file . '</info>');

        return true;
    }

    /**
     * Create a separate Composer instance from the plugins file.
     * Plugins are disabled to avoid recursion.
     */
    protected function createSeparateComposer(IOInterface $io): Composer
    {
        $file = $this->getSeparateFile();
        $factory = new Factory();

        return $factory->createComposer($io, $file, true, getcwd() ?: '.', true, true);
    }
}
