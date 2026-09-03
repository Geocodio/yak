<?php

namespace App\DataTransferObjects;

/**
 * One row of the Settings > MCP servers table. Mirrors the `McpServerRow`
 * TypeScript type in resources/js/types/settings.ts.
 */
final readonly class McpServer
{
    public function __construct(
        public string $name,
        public string $displayName,
        public string $target,
        public string $transport,
        public string $source,
        public ?string $pluginName,
        public string $status,
        public ?string $detail,
        public bool $canConnect,
        public bool $canLogout,
        public bool $canRemove,
        public string $loginCommand,
    ) {}

    /**
     * @return array{name: string, displayName: string, target: string, transport: string, source: string, pluginName: ?string, status: string, detail: ?string, canConnect: bool, canLogout: bool, canRemove: bool, loginCommand: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'displayName' => $this->displayName,
            'target' => $this->target,
            'transport' => $this->transport,
            'source' => $this->source,
            'pluginName' => $this->pluginName,
            'status' => $this->status,
            'detail' => $this->detail,
            'canConnect' => $this->canConnect,
            'canLogout' => $this->canLogout,
            'canRemove' => $this->canRemove,
            'loginCommand' => $this->loginCommand,
        ];
    }
}
