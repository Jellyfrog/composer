<?php declare(strict_types=1);

namespace Composer\SeparatePlugins;

use Composer\Plugin\Capability\CommandProvider;
use Composer\SeparatePlugins\Command\RequireCommand;
use Composer\SeparatePlugins\Command\InstallCommand;
use Composer\SeparatePlugins\Command\UpdateCommand;
use Composer\SeparatePlugins\Command\RemoveCommand;

class SeparatePluginsCommandProvider implements CommandProvider
{
    /** @var array<string, mixed> */
    private array $args;

    /**
     * @param array<string, mixed> $args
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    /**
     * @return \Composer\Command\BaseCommand[]
     */
    public function getCommands(): array
    {
        return [
            new RequireCommand(),
            new InstallCommand(),
            new UpdateCommand(),
            new RemoveCommand(),
        ];
    }
}
