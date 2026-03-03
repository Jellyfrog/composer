<?php declare(strict_types=1);

namespace Composer\SeparatePlugins\Command;

use Composer\Config\JsonConfigSource;
use Composer\DependencyResolver\Request;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveCommand extends SeparatePluginsBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('plugins:remove')
            ->setDescription('Remove packages from composer-plugins.json')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Packages to remove.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Removes a package from the require-dev section.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Only remove from composer-plugins.json, do not uninstall.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Skip the install step after updating the lock file.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('update-with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Allows all inherited dependencies to be updated.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements.'),
            ])
            ->setHelp(
                <<<EOT
The <info>plugins:remove</info> command removes packages from the separate
composer-plugins.json file and uninstalls them from vendor-plugins/.

This does not touch the main composer.json, composer.lock, or vendor/ directory.

<info>composer plugins:remove vendor/package</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $file = $this->getSeparateFile();

        if (!file_exists($file)) {
            $io->writeError('<error>No ' . $file . ' found.</error>');

            return 1;
        }

        $packages = $input->getArgument('packages');
        $packages = array_map('strtolower', $packages);

        $jsonFile = new JsonFile($file);
        /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $composerData */
        $composerData = $jsonFile->read();
        $composerBackup = file_get_contents($jsonFile->getPath());

        $json = new JsonConfigSource($jsonFile);

        $type = $input->getOption('dev') ? 'require-dev' : 'require';
        $altType = !$input->getOption('dev') ? 'require-dev' : 'require';
        $dryRun = $input->getOption('dry-run');

        // Normalize case
        foreach (['require', 'require-dev'] as $linkType) {
            if (isset($composerData[$linkType])) {
                foreach ($composerData[$linkType] as $name => $version) {
                    $composerData[$linkType][strtolower($name)] = $name;
                }
            }
        }

        foreach ($packages as $package) {
            if (isset($composerData[$type][$package])) {
                if (!$dryRun) {
                    $json->removeLink($type, $composerData[$type][$package]);
                }
            } elseif (isset($composerData[$altType][$package])) {
                $io->writeError('<warning>' . $package . ' is in ' . $altType . ', not ' . $type . '. Removing from ' . $altType . '.</warning>');
                if (!$dryRun) {
                    $json->removeLink($altType, $composerData[$altType][$package]);
                }
            } else {
                $io->writeError('<warning>' . $package . ' is not required in ' . $file . ' and has not been removed.</warning>');
            }
        }

        $io->writeError('<info>' . $file . ' has been updated</info>');

        if ($input->getOption('no-update')) {
            return 0;
        }

        $composer = $this->createSeparateComposer($io);
        $composer->getInstallationManager()->setOutputProgress(!$input->getOption('no-progress'));

        $install = Installer::create($io, $composer);

        $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
        if ($input->getOption('update-with-all-dependencies')) {
            $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
        }

        $install
            ->setVerbose((bool) $input->getOption('verbose'))
            ->setDevMode(true)
            ->setUpdate(true)
            ->setInstall(!$input->getOption('no-install'))
            ->setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
            ->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input))
            ->setDryRun($dryRun)
        ;

        if ($composer->getLocker()->isLocked()) {
            $install->setUpdateAllowList($packages);
        }

        $status = $install->run();
        if ($status !== 0) {
            $io->writeError("\n" . '<error>Removal failed, reverting ' . $file . ' to its original content.</error>');
            file_put_contents($jsonFile->getPath(), $composerBackup);
        }

        return $status;
    }
}
