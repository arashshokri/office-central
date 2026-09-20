<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Release;
use App\Models\RepositoryIntegration;
use App\Services\AuditService;
use App\Services\PackageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RepositoryIntegrationController extends Controller
{
    public function index()
    {
        return view('admin.repositories', [
            'integrations' => RepositoryIntegration::with('product')->latest()->get(),
            'products' => Product::where('status', 'active')->get(),
        ]);
    }

    public function store(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'repository_url' => ['required', 'url', 'regex:~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$~'],
            'branch' => ['nullable', 'string', 'max:100'],
            'release_channel' => ['required', 'in:stable,beta,alpha,internal'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'auto_publish' => ['nullable', 'boolean'],
        ]);
        $integration = RepositoryIntegration::create([
            'product_id' => $data['product_id'],
            'provider' => 'github',
            'repository_url' => rtrim($data['repository_url'], '/'),
            'branch' => $data['branch'] ?: 'main',
            'release_channel' => $data['release_channel'],
            'encrypted_access_token' => $data['access_token'] ?: null,
            'enabled' => true,
            'auto_publish' => (bool) ($data['auto_publish'] ?? false),
        ]);
        $audit->record('repository.created', $integration, null, ['repository_url' => $integration->repository_url]);

        return back()->with('success', __('ui.saved'));
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
            $response = Http::withHeaders($headers)->connectTimeout(5)->timeout(30)
                ->get("https://api.github.com/repos/{$owner}/{$repository}/tags", ['per_page' => 50])
                ->throw();
            $tags = collect($response->json())->filter(fn ($tag) => is_array($tag) && isset($tag['name'], $tag['zipball_url']));
            $tag = $tags->sort(fn ($a, $b) => version_compare(ltrim($b['name'], 'vV'), ltrim($a['name'], 'vV')))->first();
            if (! $tag || ! preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $tag['name'], $matches)) {
                throw new \RuntimeException('No semantic version tag was found.');
            }

            $version = $matches[1];
            if (Release::where('product_id', $integration->product_id)->where('version', $version)->where('channel', $integration->release_channel)->exists()) {
                $integration->update(['last_sync_at' => now(), 'last_commit' => data_get($tag, 'commit.sha'), 'last_error' => null]);

                return back()->with('success', __('ui.repository_already_synced'));
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'central-release-');
            try {
                $zipballUrl = (string) $tag['zipball_url'];
                $zipballHost = strtolower((string) parse_url($zipballUrl, PHP_URL_HOST));
                if (parse_url($zipballUrl, PHP_URL_SCHEME) !== 'https' || ! in_array($zipballHost, ['api.github.com', 'codeload.github.com'], true)) {
                    throw new \RuntimeException('GitHub returned an unexpected package URL.');
                }
                Http::withHeaders($headers)->connectTimeout(5)->timeout(180)->sink($temporaryPath)->get($zipballUrl)->throw();
                $upload = new UploadedFile($temporaryPath, $tag['name'].'.zip', 'application/zip', null, true);
                $inspection = $packages->inspect($upload);
                $uuid = (string) Str::uuid();
                $filename = $tag['name'].'.zip';
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
            $integration->update(['last_sync_at' => now(), 'last_error' => Str::limit($exception->getMessage(), 1000)]);
            report($exception);

            return back()->withErrors(['repository' => __('ui.repository_sync_failed')]);
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
