<?php

declare(strict_types=1);

namespace Ethernize\Pulse\SingleFile;

use Ethernize\Weave\Source\TemplateProvider;
use Ethernize\Weave\Source\TemplateSource;

/**
 * Gives Weave the template of every single-file component under the name its class uses by default
 * ("pulse/<component-name>"). Diagnostics point at the component file and the real line in it.
 */
final class SingleFileTemplates implements TemplateProvider
{
    private const PREFIX = 'pulse/';

    public function __construct(private readonly SingleFileComponents $components)
    {
    }

    public function names(): array
    {
        return array_map(static fn (string $name): string => self::PREFIX . $name, array_keys($this->components->components()));
    }

    public function load(string $name): ?TemplateSource
    {
        if (!str_starts_with($name, self::PREFIX)) {
            return null;
        }

        $component = $this->components->components()[substr($name, strlen(self::PREFIX))] ?? null;
        $source = $component === null ? null : $this->components->source(substr($name, strlen(self::PREFIX)));

        if ($component === null || $source === null) {
            return null;
        }

        return new TemplateSource($name, $component['path'], $source->template, $source->templateLineOffset, $component['file']);
    }
}
