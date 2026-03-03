<?php declare(strict_types=1);

namespace Composer\SeparatePlugins;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // Auto-include the separate plugins autoloader if it exists
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $separateAutoloader = dirname($vendorDir) . '/vendor-plugins/autoload.php';
        if (file_exists($separateAutoloader)) {
            require_once $separateAutoloader;
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Composer\SeparatePlugins\SeparatePluginsCommandProvider',
        ];
    }
}
