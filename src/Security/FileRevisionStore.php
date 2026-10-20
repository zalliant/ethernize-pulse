<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

use RuntimeException;

/**
 * File-based store: one tiny file per component instance, locked while it is updated, so requests
 * for different instances never wait for each other and requests for the same instance take turns.
 * It needs a directory shared by every process that serves the application (a local disk for
 * one server; a shared file system that honors flock() for several).
 *
 * The revision is written as a fixed-width number, so an update is one write at the start of the
 * file, with no truncation. Files are created when the first action of an instance is processed,
 * never when a page is rendered.
 *
 * Cleanup is bounded and deterministic: files are spread over 256 directories, each with a marker
 * file. When a claim lands in a directory whose marker is older than $pruneEvery seconds, that one
 * directory is swept for files older than $ttlSeconds. $ttlSeconds must exceed the snapshot
 * lifetime: a file is only removed once every snapshot of its instance has expired, so a removal
 * can never make an old snapshot acceptable again.
 */
final class FileRevisionStore implements RevisionStore
{
    private const WIDTH = 12;

    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 172800,
        private readonly int $pruneEvery = 3600,
    ) {
    }

    public function claim(string $id, int $expected): bool
    {
        $path = $this->path($id);
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            // The directory of this bucket does not exist yet.
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new RuntimeException('Could not create the Pulse revision directory.');
            }

            $handle = fopen($path, 'c+');

            if ($handle === false) {
                throw new RuntimeException('Could not open the Pulse revision store.');
            }
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock the Pulse revision store.');
            }

            $stored = (int) fread($handle, self::WIDTH);

            if ($stored !== $expected) {
                return false;
            }

            rewind($handle);
            fwrite($handle, str_pad((string) ($expected + 1), self::WIDTH, '0', STR_PAD_LEFT));
        } finally {
            fclose($handle); // also releases the lock
        }

        // One claim in 64 looks at the marker of its directory.
        if ((hexdec($id[2]) & 3) === 0 && (hexdec($id[3]) & 3) === 0) {
            $this->pruneIfDue(dirname($path));
        }

        return true;
    }

    public function current(string $id): int
    {
        $contents = @file_get_contents($this->path($id), false, null, 0, self::WIDTH);

        return $contents === false ? 0 : (int) $contents;
    }

    private function path(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid component id.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . substr($id, 0, 2) . DIRECTORY_SEPARATOR . $id;
    }

    /**
     * Sweeps one directory when its marker says it was not swept recently. Whoever touches the
     * marker first does the sweep; the others see a fresh marker and move on.
     */
    private function pruneIfDue(string $bucket): void
    {
        $marker = $bucket . DIRECTORY_SEPARATOR . '.swept';
        $now = time();
        $last = @filemtime($marker);

        if ($last !== false && $last > $now - $this->pruneEvery) {
            return;
        }

        @touch($marker, $now);
        $limit = $now - $this->ttlSeconds;

        foreach (@scandir($bucket) ?: [] as $name) {
            if ($name[0] === '.') {
                continue;
            }

            $file = $bucket . DIRECTORY_SEPARATOR . $name;
            $modified = @filemtime($file);

            if ($modified !== false && $modified < $limit) {
                @unlink($file);
            }
        }
    }
}
