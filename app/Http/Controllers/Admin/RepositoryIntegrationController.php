<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Release;
use App\Models\RepositoryIntegration;
use App\Services\AuditService;
use App\Services\OfficeSourceService;
use App\Services\PackageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RepositoryIntegrationController extends Controller
{
    public function index()
    {
        return $this->page();
    }

    private function page(?RepositoryIntegration $integration = null)
    {
        return view('admin.repositories', [
            'integrations' => RepositoryIntegration::with('product')->latest()->get(),
            'products' => Product::where(fn ($q) => $q->where('status', 'active')->orWhere('id', $integration?->product_id))->get(),
            'editing' => $integration,
        ]);
    }

    public function edit(RepositoryIntegration $integration)
    {
        return $this->page($integration);
    }

    private function data(Request $request, ?RepositoryIntegration $integration = null): array
    {
        $url = trim((string) $request->input('repository_url'));
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $url)) {
            $url = 'https://github.com/'.$url;
        }
        $url = preg_replace('~\.git/?$~', '', rtrim($url, '/'));
        $request->merge(['repository_url' => $url, 'release_channel' => $request->input('release_channel', 'stable')]);
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'repository_url' => ['required', 'url', 'regex:~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',
                Rule::unique('repository_integrations')->where('product_id', $request->input('product_id'))->where('release_channel', $request->input('release_channel'))->ignore($integration?->id)],
            'branch' => ['nullable', 'string', 'max:100'],
            'release_channel' => ['required', 'in:stable,beta,alpha,internal'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'auto_publish' => ['nullable', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        return [
            'product_id' => $data['product_id'],
            'provider' => 'github',
            'repository_url' => rtrim($data['repository_url'], '/'),
            'branch' => ($data['branch'] ?? null) ?: ($integration?->branch ?? 'main'),
            'release_channel' => $data['release_channel'],
            'encrypted_access_token' => ($data['access_token'] ?? null) ?: $integration?->encrypted_access_token,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'auto_publish' => (bool) ($data['auto_publish'] ?? false),
        ];
    }

    public function store(Request $request, AuditService $audit)
    {
        $integration = RepositoryIntegration::create($this->data($request));
        $audit->record('repository.created', $integration, null, ['repository_url' => $integration->repository_url]);

        return redirect()->route('repositories.index')->with('success', __('ui.saved'));
    }

    public function update(Request $request, RepositoryIntegration $integration, AuditService $audit)
    {
        $integration->update($this->data($request, $integration));
        $audit->record('repository.updated', $integration, null, $integration->only(['repository_url', 'release_channel', 'enabled', 'auto_publish']));

        return redirect()->route('repositories.index')->with('success', __('ui.saved'));
    }

    public function destroy(RepositoryIntegration $integration, AuditService $audit)
    {
        $audit->record('repository.disconnected', $integration, null, ['repository_url' => $integration->repository_url]);
        $integration->delete();

        return redirect()->route('repositories.index')->with('success', __('ui.repository_disconnected'));
    }

    public function testConnection(RepositoryIntegration $integration)
    {
        [$owner,$repository] = $this->githubCoordinates($integration->repository_url);
        try {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(20);
            if ($integration->encrypted_access_token) {
                $request = $request->withToken($integration->encrypted_access_token);
            }
            $request->get("https://api.github.com/repos/{$owner}/{$repository}")->throw();
        } catch (\Throwable $error) {
            return back()->withErrors(['repository' => $this->connectionError($error)]);
        }

        return back()->with('success', __('ui.repository_connection_ok'));
    }

    private function connectionError(\Throwable $error): string
    {
        if ($error instanceof RequestException) {
            return __(['401' => 'ui.repository_auth_error', '404' => 'ui.repository_not_found', '403' => 'ui.repository_rate_error', '429' => 'ui.repository_rate_error'][$error->response->status()] ?? 'ui.repository_sync_failed');
        }
        if ($error instanceof ConnectionException) {
            return __('ui.repository_network_error');
        }

        return __('ui.repository_sync_failed');
    }

    public function sync(RepositoryIntegration $integration, PackageService $packages, AuditService $audit)
    {
        abort_unless($integration->enabled && $integration->provider === 'github', 422);
        [$owner, $repository] = $this->githubCoordinates($integration->repository_url);
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($integration->encrypted_access_token) {
            $headers['Authorization'] = 'Bearer '.$integration->encrypted_access_token;
        }

        try {
            $isOffice = $integration->product?->slug === 'office';
            $response = Http::withHeaders($headers)->connectTimeout(5)->timeout(30)
                ->get("https://api.github.com/repos/{$owner}/{$repository}/".($isOffice ? 'releases' : 'tags'), ['per_page' => 100])
                ->throw();
            $tags = collect($response->json())->filter(fn ($tag) => is_array($tag))
                ->filter(fn ($tag) => ! $isOffice || (! ($tag['draft'] ?? true)
                    && ($integration->release_channel !== 'stable' || ! ($tag['prerelease'] ?? true))))
                ->map(fn ($tag) => $isOffice ? array_merge($tag, ['name' => $tag['tag_name'] ?? '']) : $tag)
                ->filter(fn ($tag) => preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $tag['name'] ?? ''));
            if ($isOffice) {
                // Git tags also work when no GitHub Release/asset was created.
                // Prefer the runtime asset when a tag has a matching Release.
                $gitTags = Http::withHeaders($headers)->connectTimeout(5)->timeout(30)
                    ->get("https://api.github.com/repos/{$owner}/{$repository}/tags", ['per_page' => 100])->throw();
                // A draft or excluded prerelease must not be reintroduced by
                // the tag fallback just because it has no published asset.
                $releaseNames = collect($response->json())->pluck('tag_name')->filter()->all();
                $tags = $tags->concat(collect($gitTags->json())->filter(fn ($tag) => is_array($tag)
                    && preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $tag['name'] ?? '')
                    && ($integration->release_channel !== 'stable' || ! str_contains($tag['name'], '-'))
                    && ! in_array($tag['name'], $releaseNames, true)));
            }
            $tag = $tags->sort(fn ($a, $b) => version_compare(ltrim($b['name'], 'vV'), ltrim($a['name'], 'vV')))->first();
            if (! $tag || ! preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $tag['name'], $matches)) {
                throw new \RuntimeException('No semantic version tag was found.');
            }

            $version = $matches[1];
            $existing = Release::withTrashed()->where('product_id', $integration->product_id)->where('version', $version)->where('channel', $integration->release_channel)->first();
            if ($existing) {
                if (! $existing->trashed() && $existing->package_path && Storage::disk(config('office.package_disk'))->exists($existing->package_path)) {
                    $integration->update(['last_sync_at' => now(), 'last_commit' => data_get($tag, 'commit.sha'), 'last_error' => null]);

                    return back()->with('success', __('ui.repository_already_synced'));
                }
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'central-release-');
            try {
                $downloadHeaders = $headers;
                $asset = null;
                $filename = $tag['name'].'.zip';
                if ($isOffice) {
                    $architecture = config('office.runtime_architecture', 'amd64');
                    if (! in_array($architecture, ['amd64', 'arm64'], true)) {
                        throw new \RuntimeException('Invalid CENTRAL_RUNTIME_ARCHITECTURE.');
                    }
                    $filename = "office-runtime-{$version}-{$architecture}.zip";
                    $assets = collect($tag['assets'] ?? [])->filter(fn ($item) => ($item['name'] ?? '') === $filename && ($item['state'] ?? '') === 'uploaded');
                    if ($assets->count() > 1) {
                        throw new \RuntimeException('Duplicate runtime assets.');
                    }
                    if ($assets->count() === 1) {
                        $asset = $assets->first();
                        if (! is_int($asset['id'] ?? null) || ($asset['size'] ?? 0) <= 0 || $asset['size'] > 20 * 1024 ** 3) {
                            throw new \RuntimeException('Runtime asset identity or size is invalid.');
                        }
                        $zipballUrl = "https://api.github.com/repos/{$owner}/{$repository}/releases/assets/{$asset['id']}";
                        $downloadHeaders['Accept'] = 'application/octet-stream';
                    }
                }
                if (! $asset) {
                    $filename = $tag['name'].'.zip';
                    $zipballUrl = (string) ($tag['zipball_url'] ?? '');
                    $zipballHost = strtolower((string) parse_url($zipballUrl, PHP_URL_HOST));
                    if (parse_url($zipballUrl, PHP_URL_SCHEME) !== 'https' || ! in_array($zipballHost, ['api.github.com', 'codeload.github.com'], true)) {
                        throw new \RuntimeException('GitHub returned an unexpected package URL.');
                    }
                }
                Http::withHeaders($downloadHeaders)->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]])
                    ->connectTimeout(5)->timeout($isOffice ? 1800 : 180)->sink($temporaryPath)->get($zipballUrl)->throw();
                $upload = new UploadedFile($temporaryPath, $filename, 'application/zip', null, true);
                $inspection = $packages->inspect($upload);
                if ($isOffice && ! $asset) {
                    $inspection['source_manifest'] = app(OfficeSourceService::class)->inspect($upload);
                    if ($inspection['source_manifest']['version'] !== $version) {
                        throw new \RuntimeException('Office source VERSION differs from its GitHub tag.');
                    }
                }
                if ($isOffice && $asset && (($inspection['runtime_manifest']['version'] ?? '') !== $version
                    || ($inspection['runtime_manifest']['architecture'] ?? '') !== $architecture)) {
                    throw new \RuntimeException('Runtime manifest does not match the GitHub release version or architecture.');
                }
                if ($asset && $upload->getSize() !== $asset['size']) {
                    throw new \RuntimeException('Downloaded runtime size does not match GitHub.');
                }
                if ($asset && ! empty($asset['digest']) && ! hash_equals('sha256:'.$inspection['sha256'], $asset['digest'])) {
                    throw new \RuntimeException('Runtime checksum does not match the GitHub asset digest.');
                }
                $newPath = null;
                try {
                    $restored = DB::transaction(function () use ($integration, $version, $inspection, $temporaryPath, $filename, $tag, $audit, &$newPath) {
                        // Serialize imports for this connection, then recheck the record:
                        // another request may have imported or deleted it during download.
                        RepositoryIntegration::whereKey($integration->id)->lockForUpdate()->firstOrFail();
                        $release = Release::withTrashed()->where('product_id', $integration->product_id)
                            ->where('version', $version)->where('channel', $integration->release_channel)->lockForUpdate()->first();
                        if ($release && (! $release->package_path || ! $release->package_sha256
                            || ! hash_equals($release->package_sha256, $inspection['sha256']))) {
                            throw new \RuntimeException(__('ui.repository_payload_changed'));
                        }
                        $before = $release?->toArray();
                        $uuid = $release?->uuid ?? (string) Str::uuid();
                        $path = $release?->package_path ?? "packages/{$integration->product_id}/{$uuid}/{$filename}";
                        if (! $release) {
                            $newPath = $path;
                        }
                        $this->storeDownloadedPackage($temporaryPath, $path);
                        $restored = $release !== null;
                        if ($release) {
                            // Keep UUID, checksum, publication status and history unchanged.
                            if ($release->trashed()) {
                                $release->restore();
                            }
                            if (! $release->source_manifest && isset($inspection['source_manifest'])) {
                                $release->update(['source_manifest' => $inspection['source_manifest']]);
                            }
                        } else {
                            $release = Release::create([
                                'uuid' => $uuid, 'product_id' => $integration->product_id,
                                'version' => $version, 'channel' => $integration->release_channel,
                                'status' => $integration->auto_publish ? 'published' : 'draft',
                                'source_type' => 'github', 'source_reference' => $tag['name'],
                                'release_notes' => Str::limit((string) ($tag['body'] ?? ''), 20000, ''),
                                'package_filename' => $filename, 'package_path' => $path,
                                'package_size' => $inspection['size'], 'package_sha256' => $inspection['sha256'],
                                'runtime_manifest' => $inspection['runtime_manifest'] ?? null,
                                'source_manifest' => $inspection['source_manifest'] ?? null,
                                'git_commit' => data_get($tag, 'commit.sha'),
                                'published_at' => $integration->auto_publish ? now() : null,
                            ]);
                        }
                        $integration->update(['last_sync_at' => now(), 'last_commit' => data_get($tag, 'commit.sha'), 'last_error' => null]);
                        $audit->record($restored ? 'repository.release_restored' : 'repository.release_synced', $release, $before, $release->toArray());

                        return $restored;
                    });
                } catch (\Throwable $error) {
                    if ($newPath) {
                        Storage::disk(config('office.package_disk'))->delete($newPath);
                    }
                    throw $error;
                }
            } finally {
                if (isset($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        } catch (\Throwable $exception) {
            $message = $this->connectionError($exception);
            // Never persist HTTP exception text: it can include request headers.
            $detail = $exception instanceof HttpClientException ? $message : Str::limit($exception->getMessage(), 1000);
            $integration->update(['last_sync_at' => now(), 'last_error' => $detail]);
            report($exception);

            return back()->withErrors(['repository' => $message]);
        }

        return back()->with('success', __($restored ? 'ui.repository_restored' : 'ui.repository_synced'));
    }

    private function githubCoordinates(string $url): array
    {
        preg_match('~^https://github\.com/([^/]+)/([^/]+)/?$~', $url, $matches);
        abort_unless(isset($matches[1], $matches[2]), 422);

        return [$matches[1], preg_replace('/\.git$/', '', $matches[2])];
    }

    private function storeDownloadedPackage(string $temporaryPath, string $path): void
    {
        $disk = Storage::disk(config('office.package_disk'));
        $stagingPath = $path.'.sync-'.Str::uuid();
        $stream = fopen($temporaryPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('The downloaded package could not be opened.');
        }
        try {
            // The local package disk renames a complete staged file atomically.
            // A failed write cannot truncate the retained original package.
            if (! $disk->put($stagingPath, $stream) || ! $disk->move($stagingPath, $path)) {
                throw new \RuntimeException('The downloaded package could not be stored.');
            }
        } finally {
            fclose($stream);
            $disk->delete($stagingPath);
        }
    }
}
