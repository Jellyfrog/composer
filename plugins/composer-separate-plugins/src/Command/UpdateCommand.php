<?php declare(strict_types=1);

namespace Composer\SeparatePlugins\Command;

use Composer\DependencyResolver\Request;
use Composer\Installer;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends SeparatePluginsBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('plugins:update')
            ->setAliases(['plugins:upgrade'])
            ->setDescription('Update packages in composer-plugins.json')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages to update (updates all if none specified).'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist.'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables require-dev packages.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Skip the install step after updating the lock file.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump.'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only.'),
                new InputOption('with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Update also dependencies of the listed packages.'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
                new InputOption('minimal-changes', 'm', InputOption::VALUE_NONE, 'Only perform absolutely necessary changes to transitive dependencies.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements.'),
            ])
            ->setHelp(
                <<<EOT
The <info>plugins:update</info> command updates packages in the separate
composer-plugins.json file.

<info>composer plugins:update</info>
<info>composer plugins:update vendor/package</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $file = $this->getSeparateFile();

        if (!file_exists($file)) {
            $io->writeError('<error>No ' . $file . ' found. Run "composer plugins:require" first.</error>');

            return 1;
        }

        $composer = $this->createSeparateComposer($io);
        $composer->getInstallationManager()->setOutputProgress(!$input->getOption('no-progress'));

        $install = Installer::create($io, $composer);

        $config = $composer->getConfig();
        [$preferSource, $preferDist] = $this->getPreferredInstallOptions($config, $input);

        $optimize = $input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
        $minimalChanges = $input->getOption('minimal-changes') || $config->get('update-with-minimal-changes');

        $updateAllowTransitiveDependencies = Request::UPDATE_ONLY_LISTED;
        if ($input->getOption('with-all-dependencies')) {
            $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
        }

        $install
            ->setDryRun((bool) $input->getOption('dry-run'))
            ->setVerbose((bool) $input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setDumpAutoloader(!$input->getOption('no-autoloader'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setUpdate(true)
            ->setInstall(!$input->getOption('no-install'))
            ->setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
            ->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input))
            ->setPreferStable((bool) $input->getOption('prefer-stable'))
            ->setPreferLowest((bool) $input->getOption('prefer-lowest'))
            ->setMinimalUpdate($minimalChanges)
        ;

        $packages = $input->getArgument('packages');
        if (count($packages) > 0) {
            $install->setUpdateAllowList($packages);
        }

        return $install->run();
    }
}
