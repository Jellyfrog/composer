<?php declare(strict_types=1);

namespace Composer\SeparatePlugins\Command;

use Composer\Installer;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends SeparatePluginsBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('plugins:install')
            ->setDescription('Install packages from composer-plugins.lock (or composer-plugins.json)')
            ->setDefinition([
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist.'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump.'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements.'),
            ])
            ->setHelp(
                <<<EOT
The <info>plugins:install</info> command reads the composer-plugins.lock file
and installs all the packages listed in it into vendor-plugins/.

This does not touch the main composer.json, composer.lock, or vendor/ directory.

<info>composer plugins:install</info>
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

        $install
            ->setDryRun((bool) $input->getOption('dry-run'))
            ->setVerbose((bool) $input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setDumpAutoloader(!$input->getOption('no-autoloader'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input))
        ;

        return $install->run();
    }
}
