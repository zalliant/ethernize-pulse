<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Cli;

use Ethernize\Core\Cli\Generator\Names;
use Ethernize\Core\Cli\Generator\Plan;
use Ethernize\Core\Cli\Generator\Project;

/**
 * Puts a generated component into the plan in the shape the developer chose: one file with the class and the
 * template (the default), or a class file and a template file (split), and registers the split shape in the
 * configuration, which is the only place a separate class is found.
 */
final class ComponentFiles
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $folder folders below src/ ("Pulse", then sub-folders)
     * @param array{class: string, template: string} $parts
     */
    public static function add(Plan $plan, Project $project, array $folder, string $class, array $parts, bool $split): void
    {
        if (!$split) {
            $plan->add($project->sourcePath([...$folder, $class], '.pulse.php'), Stubs::single($parts));

            return;
        }

        $plan->add($project->sourcePath([...$folder, $class]), rtrim($parts['class']) . "\n");
        $plan->add($project->appPath('resources/views/pulse/' . self::name($class) . '.weave'), $parts['template']);
    }

    /**
     * The kebab-case name a component class has in Pulse and in its default template name.
     */
    public static function name(string $class): string
    {
        return Names::kebab($class);
    }

    /**
     * Adds "name => Class::class" to the components of config/pulse.php when the file has the usual shape.
     * Returns what happened, for the developer.
     *
     * @param list<string> $folder folders below src/
     */
    public static function register(Project $project, array $folder, string $class): string
    {
        $name = self::name($class);
        $fqcn = '\\' . $project->namespaceFor($folder) . '\\' . $class;
        $entry = sprintf("'%s' => %s::class,", $name, $fqcn);
        $file = $project->directory . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'pulse.php';
        $manual = sprintf('Register it in config/pulse.php, under "components": %s', $entry);

        $contents = is_file($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            return $manual . ' (and add a "key_env" setting).';
        }

        if (str_contains($contents, "'" . $name . "'")) {
            return sprintf('config/pulse.php already has a component named "%s"; check that it points at %s.', $name, $fqcn);
        }

        if (preg_match_all("/'components'\\s*=>\\s*\\[/", $contents) !== 1) {
            return $manual;
        }

        $patched = preg_replace_callback("/('components'\\s*=>\\s*\\[)([ \\t]*\\r?\\n)?/", static function (array $m) use ($entry): string {
            $newline = str_contains($m[2] ?? '', "\r\n") ? "\r\n" : "\n";

            return $m[1] . $newline . '        ' . $entry . ($m[2] ?? '') ;
        }, $contents, 1);

        if ($patched === null || file_put_contents($file, $patched, LOCK_EX) === false) {
            return $manual;
        }

        return sprintf('Registered "%s" in config/pulse.php.', $name);
    }
}
