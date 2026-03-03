<?php declare(strict_types=1);

namespace Composer\Plugin\Sideload;

use Composer\Composer;
use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class SideloadPlugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;
    private static bool $isInstallingPlugins = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Composer\Plugin\Sideload\SideloadCommandProvider',
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'onPreAutoloadDump',
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        if (self::$isInstallingPlugins) {
            return;
        }

        $pluginsFile = self::getPluginsFile($this->composer);
        if (!file_exists($pluginsFile)) {
            return;
        }

        $json = new JsonFile($pluginsFile);
        $config = $json->read();
        $pluginRequires = $config['require'] ?? [];
        if (count($pluginRequires) === 0) {
            return;
        }

        $this->io->writeError('<info>Installing sideloaded packages...</info>');

        self::$isInstallingPlugins = true;
        try {
            self::installPluginPackages($this->composer, $this->io, $pluginRequires);
        } finally {
            self::$isInstallingPlugins = false;
        }
    }

    public function onPreAutoloadDump(Event $event): void
    {
        if (self::$isInstallingPlugins) {
            return;
        }

        $pluginsFile = self::getPluginsFile($this->composer);
        if (!file_exists($pluginsFile)) {
            return;
        }

        $json = new JsonFile($pluginsFile);
        $config = $json->read();
        $pluginRequires = $config['require'] ?? [];
        if (count($pluginRequires) === 0) {
            return;
        }

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $missing = [];
        foreach (array_keys($pluginRequires) as $name) {
            if ($localRepo->findPackage($name, '*') === null) {
                $missing[] = $name;
            }
        }

        if (count($missing) > 0) {
            $this->io->writeError('<warning>Sideloaded packages missing from vendor: ' . implode(', ', $missing) . '</warning>');
            $this->io->writeError('<warning>Run "composer sideload:install" to restore them.</warning>');
        }
    }

    /**
     * Get the sideload plugins file path.
     */
    public static function getPluginsFile(Composer $composer): string
    {
        $extra = $composer->getPackage()->getExtra();

        return $extra['sideload-file'] ?? 'composer-sideload.json';
    }

    /**
     * Ensure the sideload file exists. Creates it if it doesn't.
     *
     * @return JsonFile
     */
    public static function ensurePluginsFile(Composer $composer): JsonFile
    {
        $path = self::getPluginsFile($composer);
        if (!file_exists($path)) {
            file_put_contents($path, "{\n}\n");
        }

        return new JsonFile($path);
    }

    /**
     * Add plugin requirements to the root package in memory and run the installer.
     *
     * @param array<string, string> $pluginRequires package name => constraint
     * @param string[]|null $updateAllowList packages to resolve (null = all plugin packages)
     */
    public static function installPluginPackages(Composer $composer, IOInterface $io, array $pluginRequires, ?array $updateAllowList = null): int
    {
        $rootPackage = $composer->getPackage();
        $originalRequires = $rootPackage->getRequires();

        $merged = $originalRequires;
        $versionParser = new VersionParser();
        foreach ($pluginRequires as $name => $constraint) {
            $merged[$name] = new Link(
                $rootPackage->getName(),
                $name,
                $versionParser->parseConstraints($constraint),
                Link::TYPE_REQUIRE,
                $constraint
            );
        }
        $rootPackage->setRequires($merged);

        try {
            $installer = Installer::create($io, $composer);
            $installer->setUpdate(true);
            $installer->setWriteLock(false);
            $installer->setUpdateAllowList($updateAllowList ?? array_keys($pluginRequires));
            $installer->setUpdateAllowTransitiveDependencies(Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS);
            $installer->setDumpAutoloader(true);
            $installer->setRunScripts(false);

            return $installer->run();
        } finally {
            $rootPackage->setRequires($originalRequires);
        }
    }

    /**
     * Add a requirement to the sideload file using JsonManipulator.
     */
    public static function addRequirement(string $filePath, string $package, string $constraint, bool $sortPackages = false): void
    {
        $contents = file_get_contents($filePath);
        $manipulator = new JsonManipulator($contents);
        $manipulator->addLink('require', $package, $constraint, $sortPackages);
        file_put_contents($filePath, $manipulator->getContents());
    }

    /**
     * Remove a requirement from the sideload file using JsonManipulator.
     */
    public static function removeRequirement(string $filePath, string $package): void
    {
        $contents = file_get_contents($filePath);
        $manipulator = new JsonManipulator($contents);
        $manipulator->removeSubNode('require', $package);
        file_put_contents($filePath, $manipulator->getContents());
    }
}
