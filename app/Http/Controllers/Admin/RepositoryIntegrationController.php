<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Release;
use App\Models\RepositoryIntegration;
use App\Services\AuditService;
use App\Services\PackageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            $tag = $tags->sort(fn ($a, $b) => version_compare(ltrim($b['name'], 'vV'), ltrim($a['name'], 'vV')))->first();
            if (! $tag || ! preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $tag['name'], $matches)) {
                throw new \RuntimeException('No semantic version tag was found.');
            }

            $version = $matches[1];
            $existing = Release::withTrashed()->where('product_id', $integration->product_id)->where('version', $version)->where('channel', $integration->release_channel)->first();
            if ($existing) {
                if ($existing->trashed()) {
                    throw new \RuntimeException(__('ui.repository_release_archived'));
                }
                if ($isOffice && ! $existing->runtime_manifest) {
                    throw new \RuntimeException(__('ui.repository_source_conflict'));
                }
                $integration->update(['last_sync_at' => now(), 'last_commit' => data_get($tag, 'commit.sha'), 'last_error' => null]);

                return back()->with('success', __('ui.repository_already_synced'));
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
                    if ($assets->count() !== 1 || ! is_int($assets->first()['id'] ?? null)) {
                        throw new \RuntimeException(__('ui.repository_runtime_missing', ['filename' => $filename]));
                    }
                    $asset = $assets->first();
                    if (($asset['size'] ?? 0) <= 0 || $asset['size'] > 20 * 1024 ** 3) {
                        throw new \RuntimeException('Runtime asset size is invalid.');
                    }
                    $zipballUrl = "https://api.github.com/repos/{$owner}/{$repository}/releases/assets/{$asset['id']}";
                    $downloadHeaders['Accept'] = 'application/octet-stream';
                } else {
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
                if ($isOffice && (($inspection['runtime_manifest']['version'] ?? '') !== $version
                    || ($inspection['runtime_manifest']['architecture'] ?? '') !== $architecture)) {
                    throw new \RuntimeException('Runtime manifest does not match the GitHub release version or architecture.');
                }
                if ($asset && $upload->getSize() !== $asset['size']) {
                    throw new \RuntimeException('Downloaded runtime size does not match GitHub.');
                }
                if ($asset && ! empty($asset['digest']) && ! hash_equals('sha256:'.$inspection['sha256'], $asset['digest'])) {
                    throw new \RuntimeException('Runtime checksum does not match the GitHub asset digest.');
                }
                $uuid = (string) Str::uuid();
                $path = "packages/{$integration->product_id}/{$uuid}/{$filename}";
                $stream = fopen($temporaryPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('The downloaded package could not be opened.');
                }
                try {
                    if (! Storage::disk(config('office.package_disk'))->put($path, $stream)) {
                        throw new \RuntimeException('The downloaded package could not be stored.');
                    }
                } finally {
                    fclose($stream);
                }

                $release = Release::create([
                    'uuid' => $uuid,
                    'product_id' => $integration->product_id,
                    'version' => $version,
                    'channel' => $integration->release_channel,
                    'status' => $integration->auto_publish ? 'published' : 'draft',
                    'source_type' => 'github',
                    'source_reference' => $tag['name'],
                    'package_filename' => $filename,
                    'package_path' => $path,
                    'package_size' => $inspection['size'],
                    'package_sha256' => $inspection['sha256'],
                    'runtime_manifest' => $inspection['runtime_manifest'] ?? null,
                    'git_commit' => data_get($tag, 'commit.sha'),
                    'published_at' => $integration->auto_publish ? now() : null,
                ]);
                $integration->update(['last_sync_at' => now(), 'last_commit' => data_get($tag, 'commit.sha'), 'last_error' => null]);
                $audit->record('repository.release_synced', $release, null, $release->toArray());
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

        return back()->with('success', __('ui.repository_synced'));
    }

    private function githubCoordinates(string $url): array
    {
        preg_match('~^https://github\.com/([^/]+)/([^/]+)/?$~', $url, $matches);
        abort_unless(isset($matches[1], $matches[2]), 422);

        return [$matches[1], preg_replace('/\.git$/', '', $matches[2])];
    }
}
