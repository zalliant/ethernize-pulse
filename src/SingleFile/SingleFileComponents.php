<?php

declare(strict_types=1);

namespace Ethernize\Pulse\SingleFile;

use Closure;
use Ethernize\Core\Filesystem\AtomicPhpFile;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The single-file components (*.pulse.php) of an application: finding them, loading their classes, and
 * telling Weave where their templates are.
 *
 * Finding a component reads the file with the tokenizer and never runs it (see ComponentSource). In
 * development the directories are scanned on each request, but a file is parsed again only when its size
 * or modification time changed since the last scan (the result is kept in an index file under var/). In
 * production nothing is scanned: the list comes from the Manifest, built by `manifest.php build`.
 *
 * A component class is loaded by a class loader of its own that runs before Composer's. It requires the
 * file with output buffering on and throws the buffer away: the class is declared, the template text after
 * the closing tag is never sent. The file is the real class file, so stack traces and OPcache see it as it is.
 */
final class SingleFileComponents
{
    /** @var array<string, array{class: string, path: string, file: string}>|null name => details */
    private ?array $components = null;

    private ?Closure $loader = null;

    /**
     * @param list<string> $directories directories to scan, relative to $basePath
     * @param string|null  $indexFile   where the development scan keeps its results (null: not kept)
     * @param array<string, array{class: string, file: string}>|null $frozen the list built into the Manifest; when given, nothing is scanned
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $directories,
        private readonly ?string $indexFile = null,
        private readonly ?array $frozen = null,
    ) {
    }

    /**
     * @return array<string, array{class: string, path: string, file: string}> keyed by component name
     * @throws InvalidArgumentException when a file is malformed or two files give the same name or class
     */
    public function components(): array
    {
        return $this->components ??= $this->frozen !== null ? $this->fromFrozen($this->frozen) : $this->scan();
    }

    /**
     * What goes into the Manifest: name => class and file (relative to the application).
     *
     * @return array<string, array{class: string, file: string}>
     */
    public function export(): array
    {
        return array_map(static fn (array $component): array => ['class' => $component['class'], 'file' => $component['file']], $this->components());
    }

    public function source(string $name): ?ComponentSource
    {
        $component = $this->components()[$name] ?? null;

        if ($component === null) {
            return null;
        }

        $contents = file_get_contents($component['path']);

        if ($contents === false) {
            throw new RuntimeException(sprintf('The component file %s could not be read.', $component['file']));
        }

        return ComponentSource::parse($contents, $component['path']);
    }

    /**
     * Installs the class loader. Safe to call more than once.
     */
    public function register(): void
    {
        if ($this->loader !== null) {
            return;
        }

        $this->loader = function (string $class): void {
            foreach ($this->components() as $component) {
                if ($component['class'] === $class) {
                    $this->require($component['path']);

                    return;
                }
            }
        };

        // Prepended: Composer's optimized class map may point at these files too, and requiring one without
        // the output buffering would send its template to the browser.
        spl_autoload_register($this->loader, true, true);
    }

    public function unregister(): void
    {
        if ($this->loader !== null) {
            spl_autoload_unregister($this->loader);
            $this->loader = null;
        }
    }

    private function require(string $path): void
    {
        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $file): void {
                require_once $file;
            })($path);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * @param array<string, array{class: string, file: string}> $frozen
     * @return array<string, array{class: string, path: string, file: string}>
     */
    private function fromFrozen(array $frozen): array
    {
        $components = [];

        foreach ($frozen as $name => $component) {
            // Defence in depth: whatever the Manifest says, only a *.pulse.php file inside the application is ever required.
            if (preg_match('~^[A-Za-z0-9_./-]+' . preg_quote(ComponentSource::SUFFIX, '~') . '$~', $component['file']) !== 1 || str_contains($component['file'], '..') || str_starts_with($component['file'], '/')) {
                throw new InvalidArgumentException(sprintf('The Manifest names an invalid component file for "%s". Build again: php ethernize build.', (string) $name));
            }
            $components[$name] = ['class' => $component['class'], 'path' => $this->basePath . '/' . $component['file'], 'file' => $component['file']];
        }

        return $components;
    }

    /**
     * @return array<string, array{class: string, path: string, file: string}>
     */
    private function scan(): array
    {
        $base = realpath($this->basePath);

        if ($base === false) {
            return [];
        }

        $base = str_replace('\\', '/', $base);
        $index = $this->readIndex();
        $fresh = [];
        $components = [];
        $classes = [];

        foreach ($this->files($base) as $relative => $path) {
            $stat = @stat($path);

            if ($stat === false) {
                continue;
            }

            $known = $index[$relative] ?? null;

            if ($known !== null && $known['size'] === $stat['size'] && $known['mtime'] === $stat['mtime']) {
                $entry = $known;
            } else {
                $contents = file_get_contents($path);

                if ($contents === false) {
                    throw new RuntimeException(sprintf('The component file %s could not be read.', $relative));
                }

                $source = ComponentSource::parse($contents, $relative);
                $entry = ['size' => $stat['size'], 'mtime' => $stat['mtime'], 'class' => $source->class(), 'name' => ComponentSource::nameFor($source->shortName)];
            }

            $fresh[$relative] = $entry;

            if (isset($components[$entry['name']])) {
                throw new InvalidArgumentException(sprintf('The component "%s" is defined twice: %s and %s.', $entry['name'], $components[$entry['name']]['file'], $relative));
            }

            if (isset($classes[$entry['class']])) {
                throw new InvalidArgumentException(sprintf('The class %s is declared in two files: %s and %s.', $entry['class'], $classes[$entry['class']], $relative));
            }

            $classes[$entry['class']] = $relative;
            $components[$entry['name']] = ['class' => $entry['class'], 'path' => $path, 'file' => $relative];
        }

        if ($fresh !== $index) {
            $this->writeIndex($fresh);
        }

        ksort($components);

        return $components;
    }

    /**
     * @return array<string, string> relative path => absolute path, for every *.pulse.php inside the application
     */
    private function files(string $base): array
    {
        $found = [];

        foreach ($this->directories as $directory) {
            $root = realpath($this->basePath . '/' . $directory);

            if ($root === false || !is_dir($root) || !str_starts_with(str_replace('\\', '/', $root) . '/', $base . '/')) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $path = $file->getPathname();

                if (!str_ends_with($path, ComponentSource::SUFFIX)) {
                    continue;
                }

                $real = realpath($path);

                if ($real === false || !str_starts_with(str_replace('\\', '/', $real), $base . '/')) {
                    continue;
                }

                $real = str_replace('\\', '/', $real);
                $found[substr($real, strlen($base) + 1)] = $real;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @return array<string, array{size: int, mtime: int, class: string, name: string}>
     */
    private function readIndex(): array
    {
        if ($this->indexFile === null || !is_file($this->indexFile)) {
            return [];
        }

        try {
            $index = (static fn (): mixed => include func_get_arg(0))($this->indexFile);
        } catch (\Throwable) {
            return [];
        }

        return is_array($index) ? $index : [];
    }

    /**
     * @param array<string, array{size: int, mtime: int, class: string, name: string}> $index
     */
    private function writeIndex(array $index): void
    {
        if ($this->indexFile === null) {
            return;
        }

        try {
            AtomicPhpFile::write($this->indexFile, "<?php\n\nreturn " . var_export($index, true) . ";\n");
        } catch (RuntimeException) {
            // The index is only an optimization: without it every request parses the files again.
        }
    }
}
