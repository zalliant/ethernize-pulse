<?php

declare(strict_types=1);

namespace Ethernize\Pulse\SingleFile;

use Ethernize\Core\Application;
use Ethernize\Core\Manifest\ManifestContribution;
use Ethernize\Core\Manifest\ManifestContributor;
use Ethernize\Pulse\Component;
use Ethernize\Pulse\ComponentMetadata;
use InvalidArgumentException;
use Throwable;

/**
 * Finds the single-file components when the Manifest is built and checks that each one declares a valid
 * component, so that a mistake fails the build and not the first request. The list (name, class, file) is
 * frozen into the Manifest: in production nothing is scanned or tokenized.
 *
 * The templates of these components are compiled by Weave's own contributor, from the same files.
 */
final class PulseManifestContributor implements ManifestContributor
{
    public const KEY = 'pulse';

    public function __construct(private readonly SingleFileComponents $files)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function contribute(Application $application): ManifestContribution
    {
        $components = $this->files->components();
        $sources = [];

        foreach ($components as $name => $component) {
            if (!class_exists($component['class'])) {
                throw new InvalidArgumentException(sprintf('%s does not declare the class %s.', $component['file'], $component['class']));
            }

            if (!is_subclass_of($component['class'], Component::class)) {
                throw new InvalidArgumentException(sprintf('%s: %s must extend %s.', $component['file'], $component['class'], Component::class));
            }

            try {
                ComponentMetadata::for($component['class']);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException(sprintf('%s: %s', $component['file'], $exception->getMessage()), 0, $exception);
            }

            $sources[] = $component['path'];
        }

        return new ManifestContribution(['components' => $this->files->export()], $sources);
    }
}
