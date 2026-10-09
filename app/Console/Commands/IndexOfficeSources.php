<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\OfficeSourceService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class IndexOfficeSources extends Command
{
    protected $signature = 'office:index-source-releases';

    protected $description = 'Validate retained Office source archives for signed source updates without changing their payloads';

    public function handle(OfficeSourceService $sources): int
    {
        $disk = Storage::disk(config('office.package_disk'));
        Release::whereNull('runtime_manifest')->whereNull('source_manifest')
            ->whereHas('product', fn ($q) => $q->where('slug', 'office'))->chunkById(20, function ($rows) use ($sources, $disk) {
                foreach ($rows as $release) {
                    try {
                        $path = $disk->path($release->package_path ?? '');
                        if (! is_file($path) || ! $release->package_sha256
                            || ! hash_equals($release->package_sha256, hash_file('sha256', $path))) {
                            throw new \InvalidArgumentException('Stored package missing or checksum mismatch.');
                        }
                        $manifest = $sources->inspect(new UploadedFile($path, 'source.zip', 'application/zip', null, true));
                        if ($manifest['version'] !== $release->version) {
                            throw new \InvalidArgumentException('Source VERSION differs from release version.');
                        }
                        DB::transaction(function () use ($release, $manifest) {
                            $current = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
                            if (! $current->runtime_manifest && ! $current->source_manifest
                                && $current->package_sha256 === $release->package_sha256 && $current->version === $manifest['version']) {
                                $current->update(['source_manifest' => $manifest]);
                            }
                        });
                        $this->info('Validated Office source: '.$release->version);
                    } catch (\InvalidArgumentException $error) {
                        $this->warn('Office source '.$release->version.' is not ready: '.$error->getMessage());
                    }
                }
            });

        return self::SUCCESS;
    }
}
