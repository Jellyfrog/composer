# Plan: Separate Composer Plugin File Support

## Goal

A Composer plugin that manages packages in a separate file (default: `composer-plugins.json`, configurable) alongside `composer.json`. Packages install into the same `vendor/` directory. Main `composer.json` and `composer.lock` are never modified. No lock file for the plugins file.

## Design Principles (per user feedback)

1. **PRE_AUTOLOAD_DUMP** — inject plugin packages before the autoloader is generated, not re-dump after
2. **Shared `installed.json`** — no separate tracking file, no Factory subclass
3. **Minimal overrides** — only use standard Composer public APIs, no subclasses

## Architecture

```
composer.json           → composer.lock     → vendor/composer/installed.json (shared)
composer-plugins.json   → (no lock)         →         ↑ same file
```

**Custom commands** (`plugin:require`, `plugin:remove`, `plugin:update`, `plugin:install`) handle plugin management. Each command:
1. Manipulates the plugins JSON file
2. Adds plugin requirements to the main Composer's root package (in memory only)
3. Runs `Installer` with `setUpdate(true)` + `setWriteLock(false)` + `setUpdateAllowList([plugin packages])`
4. Main packages stay at their locked versions; only plugin packages are resolved/installed
5. Autoloader is regenerated with ALL packages (main + plugins)

**POST_INSTALL_CMD / POST_UPDATE_CMD hooks** automatically re-install plugin packages after the main `composer install` or `composer update` runs. Since we share `installed.json`, the main Composer removes plugin packages (they're not in `composer.lock`). The hook detects this and re-installs them. A static flag prevents recursion (the re-install's own POST event is suppressed via `setRunScripts(false)`).

**PRE_AUTOLOAD_DUMP hook** ensures that when `composer dump-autoload` runs standalone, plugin packages in the local repo are included in the autoloader.

## Files to Create

All under `src/Composer/Plugin/SeparateFile/`:

### 1. `SeparateFilePlugin.php`

Main plugin class. Minimal — just wiring.

```php
class SeparateFilePlugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}
    public function uninstall(Composer $composer, IOInterface $io): void {}

    public function getCapabilities(): array
    {
        return [CommandProvider::class => SeparateFileCommandProvider::class];
    }

    private static bool $isInstallingPlugins = false;

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
        // Re-install plugin packages that were removed by the main operation
        $pluginsFile = self::getPluginsFile($this->composer);
        if (!file_exists($pluginsFile)) {
            return;
        }
        self::$isInstallingPlugins = true;
        try {
            $this->installPluginPackages();
        } finally {
            self::$isInstallingPlugins = false;
        }
    }

    public function onPreAutoloadDump(Event $event): void
    {
        // Ensure plugin packages in localRepo are included
        // in the autoloader being generated
    }

    // Helper: get plugins file path from extra config
    public static function getPluginsFile(Composer $composer): string
    {
        $extra = $composer->getPackage()->getExtra();
        return $extra['separate-plugin-file'] ?? 'composer-plugins.json';
    }
}
```

### 2. `SeparateFileCommandProvider.php`

Standard CommandProvider. Returns array of command instances.

### 3. `Command/PluginRequireCommand.php` — `plugin:require <packages>`

Core flow:
```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = $this->getIO();
    $composer = $this->requireComposer();
    $pluginsFile = SeparateFilePlugin::getPluginsFile($composer);

    // 1. Parse package arguments
    $packages = $input->getArgument('packages');
    $requirements = $this->determineRequirements($input, $output, $packages, ...);

    // 2. Add to plugins JSON file
    $json = new JsonFile($pluginsFile);
    // Create file if needed, add requirements via JsonManipulator

    // 3. Read all plugin requirements
    $pluginRequires = $json->read()['require'] ?? [];

    // 4. Add plugin requirements to root package (in memory)
    $rootPackage = $composer->getPackage();
    $originalRequires = $rootPackage->getRequires();
    $merged = $originalRequires;
    foreach ($pluginRequires as $name => $constraint) {
        $merged[$name] = new Link(
            $rootPackage->getName(), $name,
            (new VersionParser())->parseConstraints($constraint),
            Link::TYPE_REQUIRE, $constraint
        );
    }
    $rootPackage->setRequires($merged);

    // 5. Run Installer
    $installer = Installer::create($io, $composer);
    $installer->setUpdate(true);
    $installer->setWriteLock(false);
    $installer->setUpdateAllowList(array_keys($pluginRequires));
    $installer->setUpdateAllowTransitiveDependencies(
        Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS
    );
    $installer->setDumpAutoloader(true);
    $installer->setRunScripts(false);
    $result = $installer->run();

    // 6. Restore root package
    $rootPackage->setRequires($originalRequires);

    return $result;
}
```

### 4. `Command/PluginRemoveCommand.php` — `plugin:remove <packages>`

Same pattern but:
- Removes packages from plugins JSON via `JsonManipulator::removeSubNode('require', $name)`
- Adds only REMAINING plugin requirements to root package
- Uses `setUpdateAllowList()` including the removed packages (so they get uninstalled)

### 5. `Command/PluginUpdateCommand.php` — `plugin:update [packages]`

- Reads current plugins file
- Adds all plugin requirements to root package
- If specific packages given: `setUpdateAllowList([those packages])`
- If no packages given: `setUpdateAllowList([all plugin packages])`

### 6. `Command/PluginInstallCommand.php` — `plugin:install`

Same as update with all plugin packages. Also called internally by the `POST_INSTALL_CMD` / `POST_UPDATE_CMD` hook (via `SeparateFilePlugin::installPluginPackages()`). Can be run manually too.

## Key Composer APIs Used (all public, stable)

| API | Purpose |
|-----|---------|
| `Installer::create()` | Create installer from Composer instance |
| `Installer::setUpdate(true)` | Enable dependency resolution |
| `Installer::setWriteLock(false)` | Don't modify composer.lock |
| `Installer::setUpdateAllowList()` | Only resolve plugin packages |
| `Installer::setUpdateAllowTransitiveDependencies()` | Install plugin deps too |
| `Installer::setDumpAutoloader(true)` | Regenerate autoloader |
| `Installer::setRunScripts(false)` | Don't run post-install scripts |
| `RootPackageInterface::setRequires()` | Add plugin requirements in memory |
| `RootPackageInterface::getRequires()` | Read current requirements |
| `JsonFile` / `JsonManipulator` | Read/write plugins JSON file |
| `VersionParser::parseConstraints()` | Parse version constraints |
| `Link` | Create requirement links |

No subclasses. No overrides. No private/protected access. All public Composer APIs.

## Implementation Order

1. `SeparateFilePlugin.php` + `SeparateFileCommandProvider.php` (skeleton)
2. `PluginRequireCommand.php` (the core command, proves the approach works)
3. `PluginRemoveCommand.php`
4. `PluginInstallCommand.php`
5. `PluginUpdateCommand.php`
6. PRE_AUTOLOAD_DUMP hook on the plugin
7. Manual testing

## Testing

1. `php bin/composer plugin:require psr/log:^3.0`
   - Creates `composer-plugins.json`
   - Installs psr/log to vendor/
   - `installed.json` has psr/log
   - Autoloader works for `Psr\Log\LoggerInterface`
   - `composer.json` and `composer.lock` unchanged

2. `php bin/composer plugin:remove psr/log`
   - Removes from plugins file
   - Uninstalls from vendor/

3. `php bin/composer install` (with plugin packages previously installed)
   - Main install runs, removes plugin packages from installed.json
   - POST_INSTALL_CMD hook fires, re-installs plugin packages automatically
   - No wrapper script needed

4. `php bin/composer dump-autoload`
   - PRE_AUTOLOAD_DUMP ensures plugin packages remain in autoloader
