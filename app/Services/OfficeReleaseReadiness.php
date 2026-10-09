<?php

namespace App\Services;

use App\Models\Release;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class OfficeReleaseReadiness
{
    public function inspect(Release $release): ?string
    {
        if ($release->runtime_manifest || $release->source_manifest) {
            return null;
        }
        try {
            $path = Storage::disk(config('office.package_disk'))->path($release->package_path ?? '');
            if (! is_file($path) || ! $release->package_sha256 || ! hash_equals($release->package_sha256, hash_file('sha256', $path))) {
                throw new \InvalidArgumentException(__('ui.package_missing_or_changed'));
            }
            $manifest = app(OfficeSourceService::class)->inspect(new UploadedFile($path, 'source.zip', 'application/zip', null, true));
            if ($manifest['version'] !== $release->version) {
                throw new \InvalidArgumentException(__('ui.source_version_mismatch'));
            }
            DB::transaction(function () use ($release, $manifest) {
                $current = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
                if (! $current->runtime_manifest && ! $current->source_manifest && $current->package_sha256 === $release->package_sha256 && $current->version === $manifest['version']) {
                    $current->update(['source_manifest' => $manifest]);
                }
            });
            $release->refresh();

            return null;
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }
    }
}
