<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReleaseUploadService
{
    public const CHUNK_BYTES = 262144;

    public const MAX_BYTES = 1073741824;

    private function path(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);

        return Storage::disk('local')->path('release-uploads/'.$id);
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['package' => __('ui.'.$key)]);
    }

    private function writeMetadata(string $path, array $metadata): void
    {
        $temporary = $path.'/metadata.tmp';
        if (@file_put_contents($temporary, json_encode($metadata, JSON_THROW_ON_ERROR)) === false
            || ! @rename($temporary, $path.'/metadata.json')) {
            $this->invalid('package_storage_failed');
        }
    }

    private function locked(string $id, int $owner, callable $operation): mixed
    {
        $path = $this->path($id);
        abort_unless(is_file($path.'/metadata.json'), 404);
        $lock = fopen($path.'/lock', 'c+');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            $this->invalid('package_storage_failed');
        }
        try {
            $metadata = json_decode(file_get_contents($path.'/metadata.json'), true, 32, JSON_THROW_ON_ERROR);
            abort_unless($metadata['owner'] === $owner, 404);
            if ($metadata['expires'] < time()) {
                $this->invalid('upload_expired');
            }

            return $operation($path, $metadata);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function create(int $owner, string $filename, int $size, ?int $releaseId): array
    {
        $disk = Storage::disk('local');
        if (! $disk->makeDirectory('release-uploads')) {
            $this->invalid('package_storage_failed');
        }
        $lock = fopen($disk->path('release-uploads/owner-'.$owner.'.lock'), 'c+');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            $this->invalid('package_storage_failed');
        }
        try {
            $this->prune();
            $active = 0;
            foreach ($disk->directories('release-uploads') as $directory) {
                $metadata = json_decode($disk->get($directory.'/metadata.json') ?? '{}', true);
                if (($metadata['owner'] ?? null) === $owner && empty($metadata['response'])) {
                    $active++;
                }
            }
            if ($active >= 3) {
                $this->invalid('upload_pending_limit');
            }
            // The assembled file and permanent package temporarily coexist.
            if (disk_free_space($disk->path('release-uploads')) < $size * 2 + 10485760) {
                $this->invalid('upload_disk_full');
            }
            $id = (string) Str::uuid();
            if (! $disk->makeDirectory('release-uploads/'.$id)) {
                $this->invalid('package_storage_failed');
            }
            $path = $this->path($id);
            $this->writeMetadata($path, ['owner' => $owner, 'filename' => $filename, 'size' => $size, 'offset' => 0,
                'release_id' => $releaseId, 'expires' => time() + 86400]);
            if (@file_put_contents($path.'/payload', '') === false) {
                $this->invalid('package_storage_failed');
            }

            return ['id' => $id, 'chunk_bytes' => self::CHUNK_BYTES, 'offset' => 0];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function append(string $id, int $owner, int $offset, UploadedFile $file): array
    {
        return $this->locked($id, $owner, function ($path, $metadata) use ($offset, $file) {
            abort_if(isset($metadata['response']), 409);
            $bytes = file_get_contents($file->getRealPath());
            $length = strlen($bytes);
            if ($length < 1 || $length > self::CHUNK_BYTES || $offset + $length > $metadata['size']) {
                $this->invalid('upload_chunk_invalid');
            }
            $payload = @fopen($path.'/payload', 'c+b');
            if (! $payload) {
                $this->invalid('package_storage_failed');
            }
            try {
                $current = fstat($payload)['size'];
                // Recover an unacknowledged partial write after a worker crash.
                if ($current > $metadata['offset']) {
                    if (! @ftruncate($payload, $metadata['offset'])) {
                        $this->invalid('package_storage_failed');
                    }
                    $current = $metadata['offset'];
                }
                abort_unless($current === $metadata['offset'], 409);
                if ($offset < $current && $offset + $length <= $current) {
                    // An acknowledgement can be lost. Repeating an identical chunk
                    // is safe; a conflicting retry must never corrupt the ZIP.
                    fseek($payload, $offset);
                    abort_unless(hash_equals(hash('sha256', $bytes), hash('sha256', fread($payload, $length))), 409);
                } else {
                    abort_unless($offset === $current, 409);
                    fseek($payload, $current);
                    if (@fwrite($payload, $bytes) !== $length || ! @fflush($payload)) {
                        @ftruncate($payload, $current);
                        $this->invalid('package_storage_failed');
                    }
                    $metadata['offset'] = $current + $length;
                    $this->writeMetadata($path, $metadata);
                }

                return ['offset' => fstat($payload)['size']];
            } finally {
                fclose($payload);
            }
        });
    }

    public function finish(Request $request, ?int $releaseId, callable $save): mixed
    {
        return $this->locked((string) $request->input('package_upload'), $request->user()->id,
            function ($path, $metadata) use ($request, $releaseId, $save) {
                abort_unless($metadata['release_id'] === $releaseId, 409);
                if (isset($metadata['response'])) {
                    return response()->json($metadata['response']);
                }
                if ($metadata['offset'] !== $metadata['size'] || filesize($path.'/payload') !== $metadata['size']) {
                    $this->invalid('upload_incomplete');
                }
                // Use the same ZIP, VERSION, uniqueness and publication validation
                // as a normal multipart upload; never bypass the release controller.
                $request->files->set('package', new UploadedFile($path.'/payload', $metadata['filename'], 'application/zip', null, true));
                $response = $save();
                $metadata['response'] = $response->getData(true);
                $this->writeMetadata($path, $metadata);
                unlink($path.'/payload');

                return $response;
            });
    }

    public function prune(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->directories('release-uploads') as $directory) {
            $path = $disk->path($directory);
            $lock = fopen($path.'/lock', 'c+');
            if (! $lock) {
                continue;
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $metadata = is_file($path.'/metadata.json') ? json_decode(file_get_contents($path.'/metadata.json'), true) : null;
                if (($metadata['expires'] ?? filemtime($path) + 86400) < time()) {
                    $disk->deleteDirectory($directory);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function discard(string $id, int $owner): void
    {
        $this->locked($id, $owner, function ($path, $metadata) {
            $metadata['expires'] = 0;
            $this->writeMetadata($path, $metadata);
            if (is_file($path.'/payload')) {
                unlink($path.'/payload');
            }
        });
    }
}
