<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Cli;

use Ethernize\Core\Application;
use Ethernize\Core\Cli\DoctorCheck;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Pulse\Component;
use Ethernize\Pulse\ComponentMetadata;
use Ethernize\Pulse\ComponentRegistry;
use Ethernize\Pulse\Security\SnapshotCodec;
use Throwable;

/**
 * `php ethernize doctor` for Pulse: every registered component, single-file or separate, is a valid component
 * with its template, and the folder for revisions is writable.
 */
final class PulseDoctorCheck implements DoctorCheck
{
    public function run(Application $app, Project $project): array
    {
        if (!$app->container()->has(ComponentRegistry::class)) {
            return [];
        }

        $registry = $app->container()->get(ComponentRegistry::class);
        $rows = [];
        $bad = 0;

        foreach ($registry->names() as $name) {
            $class = $registry->class($name);

            try {
                if ($class === null || !class_exists($class) || !is_subclass_of($class, Component::class)) {
                    throw new \InvalidArgumentException(sprintf('%s is not a class extending %s', (string) $class, Component::class));
                }

                ComponentMetadata::for($class);
            } catch (Throwable $error) {
                $bad++;
                $rows[] = ['FAIL', sprintf('Pulse component "%s": %s', $name, $error->getMessage()), 'Fix the class (state and actions need attributes and supported types); the message names the property.'];
            }
        }

        if ($bad === 0) {
            $rows[] = ['ok', sprintf('Pulse: %d component%s valid', count($registry->names()), count($registry->names()) === 1 ? '' : 's')];
        }

        // The signing key is read the first time a snapshot is made. Outside development a missing key must be found now,
        // not by the first visitor. (In development a key file is created on demand, so it is not touched here.)
        if (!in_array($app->environment(), ['local', 'development', 'testing'], true)) {
            try {
                $app->container()->get(SnapshotCodec::class);
                $rows[] = ['ok', 'the Pulse signing key is set'];
            } catch (Throwable $error) {
                $rows[] = ['FAIL', 'Pulse cannot sign snapshots: ' . $error->getMessage(), 'Set the environment variable named by "key_env" in config/pulse.php (PULSE_KEY by default). Generate one: php -r "echo \'base64:\' . base64_encode(random_bytes(32)), PHP_EOL;"'];
            }
        }

        $revisions = $app->basePath('var/pulse');
        $rows[] = is_dir($revisions) ? (is_writable($revisions) ? ['ok', 'var/pulse is writable'] : ['FAIL', 'var/pulse is not writable', 'Pulse stores snapshot revisions there.']) : ['ok', 'var/pulse is created on first use'];

        return $rows;
    }
}
