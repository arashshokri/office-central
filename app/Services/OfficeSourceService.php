<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

final class OfficeSourceService
{
    public const REQUIRED = ['artisan', 'Dockerfile', 'VERSION', 'composer.json', 'composer.lock',
        'bootstrap/providers.php', 'docker/Caddyfile', 'app/Providers/OfficeLicenseServiceProvider.php'];

    public function inspect(UploadedFile $file): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath(), \ZipArchive::CHECKCONS) !== true) {
            throw new \InvalidArgumentException('Invalid Office source ZIP.');
        }
        try {
            $roots = [];
            $seen = [];
            $expanded = 0;
            if ($zip->numFiles < 1 || $zip->numFiles > 100000) {
                throw new \InvalidArgumentException('Source ZIP exceeds the file count limit.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                $parts = explode('/', rtrim($name, '/'));
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                $mode = ($attributes >> 16) & 0170000;
                if ($name === '' || str_contains($name, '\\') || str_contains($name, ':') || str_contains($name, "\0")
                    || str_starts_with($name, '/') || array_intersect($parts, ['', '.', '..'])
                    || isset($seen[strtolower(rtrim($name, '/'))]) || ! in_array($mode, [0, 0040000, 0100000], true)) {
                    throw new \InvalidArgumentException('Unsafe or duplicate source ZIP entry.');
                }
                $seen[strtolower(rtrim($name, '/'))] = true;
                if (array_intersect($parts, ['.git', 'node_modules', '.env', '.env.docker', '.env.local'])
                    || preg_match('~(^|/)(database\.sqlite(?:-.*)?|identity\.json|device\.key)$~', $name)) {
                    throw new \InvalidArgumentException('Source ZIP contains credentials, database or local dependencies. Use the GitHub source ZIP.');
                }
                $expanded += $entry['size'];
                if ($expanded > 4 * 1024 ** 3) {
                    throw new \InvalidArgumentException('Expanded Office source exceeds 4 GiB.');
                }
                if ($name === 'artisan' || preg_match('~^[^/]+/artisan$~D', $name)) {
                    $roots[] = $name === 'artisan' ? '' : substr($name, 0, -7);
                }
            }
            if (count($roots) !== 1) {
                throw new \InvalidArgumentException('ZIP must contain one Office source root.');
            }
            $root = $roots[0];
            if ($root !== '' && ! preg_match('~^[A-Za-z0-9_.-]+/$~D', $root)) {
                throw new \InvalidArgumentException('Office source root must have a simple directory name.');
            }
            foreach (array_keys($seen) as $name) {
                if ($root !== '' && $name !== strtolower(rtrim($root, '/')) && ! str_starts_with($name, strtolower($root))) {
                    throw new \InvalidArgumentException('Source ZIP entry is outside Office root.');
                }
                $relative = substr($name, strlen($root));
                if ($relative === 'vendor' || str_starts_with($relative, 'vendor/')) {
                    throw new \InvalidArgumentException('Source ZIP contains local Composer dependencies.');
                }
            }
            foreach (self::REQUIRED as $name) {
                $entry = $zip->statName($root.$name);
                if (! $entry || $entry['size'] < 1 || $entry['size'] > 5 * 1024 ** 2) {
                    throw new \InvalidArgumentException('Office source is missing or has an invalid '.$name.'.');
                }
            }
            if ($zip->statName($root.'VERSION')['size'] > 100) {
                throw new \InvalidArgumentException('Office VERSION is invalid.');
            }
            $version = trim($zip->getFromName($root.'VERSION'));
            if (! preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $version)
                || ! str_contains($zip->getFromName($root.'Dockerfile'), 'FROM production AS managed')
                || ! str_contains($zip->getFromName($root.'bootstrap/providers.php'), 'OfficeLicenseServiceProvider')) {
                throw new \InvalidArgumentException('Office source must have a valid VERSION and managed helper integration.');
            }

            return ['format' => 'office-source-v1', 'product' => 'office', 'version' => $version,
                'source_protection' => 'none', 'architecture' => 'any', 'source_root' => $root];
        } finally {
            $zip->close();
        }
    }
}
