<?php declare(strict_types=1);

namespace Composer\SeparatePlugins\Command;

use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Version\VersionParser;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Composer\Util\Filesystem;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RequireCommand extends SeparatePluginsBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('plugins:require')
            ->setDescription('Add packages to the separate composer-plugins.json and install them')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Packages to require with optional version constraint, e.g. foo/bar:^1.0'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Only add to composer-plugins.json, do not install.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Skip the install step after updating the lock file.'),
                new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding.'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist.'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements.'),
                new InputOption('update-with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Allows all inherited dependencies to be updated.'),
            ])
            ->setHelp(
                <<<EOT
The <info>plugins:require</info> command adds required packages to a separate
composer-plugins.json file and installs them into vendor-plugins/.

This does not modify the main composer.json or composer.lock.

<info>composer plugins:require vendor/package:^1.0</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $file = $this->getSeparateFile();
        $newlyCreated = $this->ensureSeparateFileExists($io);

        $json = new JsonFile($file);
        $lock = $this->getSeparateLockFile();
        $composerBackup = file_get_contents($json->getPath());
        $lockBackup = file_exists($lock) ? file_get_contents($lock) : null;

        $signalHandler = SignalHandler::create([SignalHandler::SIGINT, SignalHandler::SIGTERM, SignalHandler::SIGHUP], function (string $signal, SignalHandler $handler) use ($io, $file, $json, $composerBackup, $lock, $lockBackup, $newlyCreated): void {
            $io->writeError('Received ' . $signal . ', aborting');
            if ($newlyCreated) {
                @unlink($json->getPath());
                if (file_exists($lock)) {
                    @unlink($lock);
                }
            } else {
                file_put_contents($json->getPath(), $composerBackup);
                if ($lockBackup !== null) {
                    file_put_contents($lock, $lockBackup);
                }
            }
            $handler->exitWithLastSignal();
        });

        $packages = $input->getArgument('packages');
        $requirements = $this->formatRequirements($packages);

        // Validate constraints
        $versionParser = new VersionParser();
        foreach ($requirements as $package => $constraint) {
            $versionParser->parseConstraints($constraint);
        }

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $sortPackages = $input->getOption('sort-packages');
        $dryRun = $input->getOption('dry-run');

        if (!$dryRun) {
            $this->updateFile($json, $requirements, $requireKey, $sortPackages);
        }

        $io->writeError('<info>' . $file . ' has been ' . ($newlyCreated ? 'created' : 'updated') . '</info>');

        if ($input->getOption('no-update')) {
            $signalHandler->unregister();

            return 0;
        }

        try {
            $composer = $this->createSeparateComposer($io);
            $composer->getInstallationManager()->setOutputProgress(!$input->getOption('no-progress'));

            $install = Installer::create($io, $composer);

            [$preferSource, $preferDist] = $this->getPreferredInstallOptions($composer->getConfig(), $input);

            $install
                ->setDryRun($dryRun)
                ->setVerbose((bool) $input->getOption('verbose'))
                ->setPreferSource($preferSource)
                ->setPreferDist($preferDist)
                ->setDevMode(!$input->getOption('dev') || true)
                ->setUpdate(true)
                ->setInstall(!$input->getOption('no-install'))
                ->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input))
            ;

            if ($input->getOption('update-with-all-dependencies')) {
                $install->setUpdateAllowTransitiveDependencies(\Composer\DependencyResolver\Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS);
            }

            // If lock exists, do partial update for just the new packages
            if ($composer->getLocker()->isLocked()) {
                $install->setUpdateAllowList(array_keys($requirements));
            }

            $status = $install->run();

            if ($status !== 0 && $status !== Installer::ERROR_AUDIT_FAILED) {
                if ($newlyCreated) {
                    $io->writeError("\n" . '<error>Installation failed, deleting ' . $file . '.</error>');
                    @unlink($json->getPath());
                    if (file_exists($lock)) {
                        @unlink($lock);
                    }
                } else {
                    $io->writeError("\n" . '<error>Installation failed, reverting ' . $file . ' to its original content.</error>');
                    file_put_contents($json->getPath(), $composerBackup);
                    if ($lockBackup !== null) {
                        file_put_contents($lock, $lockBackup);
                    }
                }
            }

            return $status;
        } finally {
            $signalHandler->unregister();
        }
    }

    /**
     * @param array<string, string> $requirements
     */
    private function updateFile(JsonFile $json, array $requirements, string $requireKey, bool $sortPackages): void
    {
        $contents = file_get_contents($json->getPath());
        $manipulator = new JsonManipulator($contents);

        foreach ($requirements as $package => $constraint) {
            if (!$manipulator->addLink($requireKey, $package, $constraint, $sortPackages)) {
                // Fall back to full rewrite
                $composerDefinition = $json->read();
                foreach ($requirements as $pkg => $ver) {
                    $composerDefinition[$requireKey][$pkg] = $ver;
                }
                $json->write($composerDefinition);

                return;
            }
        }

        file_put_contents($json->getPath(), $manipulator->getContents());
    }
}
