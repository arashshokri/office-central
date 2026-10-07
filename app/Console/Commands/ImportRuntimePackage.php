<?php
namespace App\Console\Commands;

use App\Models\{Product, Release};
use App\Services\PackageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ImportRuntimePackage extends Command
{
    protected $signature = 'office:import-runtime {file} {--product=office} {--channel=stable} {--publish}';
    protected $description = 'Validate and import a protected Office runtime bundle without web upload size limits';

    public function handle(PackageService $packages): int
    {
        $product = Product::where('slug', $this->option('product'))->where('status', 'active')->firstOrFail();
        $file = realpath($this->argument('file'));
        if (! $file || ! is_file($file)) { $this->error('Package file is missing.'); return self::FAILURE; }
        if (! in_array($this->option('channel'), ['stable', 'beta', 'alpha', 'internal'], true)) { $this->error('Invalid channel.'); return self::FAILURE; }
        $uploaded = new UploadedFile($file, basename($file), 'application/zip', null, true);
        $inspection = $packages->inspect($uploaded);
        $manifest = $inspection['runtime_manifest'] ?? null;
        if (! $manifest || $manifest['product'] !== $product->slug) { $this->error('Protected runtime manifest does not match the product.'); return self::FAILURE; }
        $uuid = (string) Str::uuid();
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file));
        $path = "packages/{$product->id}/{$uuid}/{$name}";
        $disk = Storage::disk(config('office.package_disk'));
        $stream = fopen($file, 'rb');
        try {
            if (! $disk->put($path, $stream)) { throw new \RuntimeException('Package storage failed.'); }
            $release = Release::create([
                'uuid' => $uuid, 'product_id' => $product->id, 'version' => $manifest['version'],
                'channel' => $this->option('channel'), 'source_type' => 'manual',
                'status' => $this->option('publish') ? 'published' : 'draft',
                'published_at' => $this->option('publish') ? now() : null,
                'package_path' => $path, 'package_filename' => $name, 'package_size' => $inspection['size'],
                'package_sha256' => $inspection['sha256'], 'runtime_manifest' => $manifest,
            ]);
        } catch (\Throwable $error) { $disk->delete($path); throw $error; }
        finally { fclose($stream); }
        $this->info('Runtime release imported: '.$release->uuid);
        return self::SUCCESS;
    }
}
