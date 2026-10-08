<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

final class PackageService
{
    public function inspect(UploadedFile $file): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath(), \ZipArchive::CHECKCONS) !== true) {
            throw new \InvalidArgumentException('Package is not a valid ZIP.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 100000) {
                throw new \InvalidArgumentException('Invalid ZIP entry count.');
            }
            $total = 0; $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $name = $entry['name'];
                $zip->getExternalAttributesIndex($i, $opsys, $attrs);
                if (isset($names[$name]) || str_contains($name, '\\') || str_contains($name, "\0")
                    || str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name)
                    || (($attrs >> 16) & 0170000) === 0120000) {
                    throw new \InvalidArgumentException('Unsafe or duplicate ZIP entry.');
                }
                $names[$name] = $entry;
                $total += $entry['size'];
                if ($total > 20 * 1024 ** 3) {
                    throw new \InvalidArgumentException('Expanded package exceeds 20 GiB.');
                }
            }
            $result = ['sha256' => hash_file('sha256', $file->getRealPath()), 'size' => $file->getSize()];
            if (isset($names['manifest.json'])) {
                if ($names['manifest.json']['size'] > 65536) {
                    throw new \InvalidArgumentException('Manifest exceeds 64 KiB.');
                }
                $manifest = json_decode($zip->getFromName('manifest.json'), true, 32, JSON_THROW_ON_ERROR);
                $this->validateManifest($manifest, $names);
                foreach ($manifest['images'] as $image) {
                    $stream = $zip->getStream($image['archive']);
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream); fclose($stream);
                    if (! hash_equals($image['sha256'], hash_final($hash))) {
                        throw new \InvalidArgumentException('Image archive checksum does not match.');
                    }
                }
                $result['runtime_manifest'] = $manifest;
            }
            return $result;
        } finally { $zip->close(); }
    }

    private function validateManifest(array $manifest, array $names): void
    {
        if (($manifest['format'] ?? '') !== 'office-runtime-v1' || ($manifest['product'] ?? '') !== 'office'
            || ! in_array($manifest['source_protection'] ?? '', ['none', 'ioncube'], true)
            || ! in_array($manifest['architecture'] ?? '', ['amd64', 'arm64'], true)
            || ! preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $manifest['version'] ?? '')
            || ! is_array($manifest['images'] ?? null) || count($manifest['images']) !== 5) {
            throw new \InvalidArgumentException('Invalid protected Office runtime manifest.');
        }
        $roles = []; $expected = ['manifest.json'];
        foreach ($manifest['images'] as $image) {
            if (! in_array($image['role'] ?? '', ['app', 'db', 'redis', 'rdp-web', 'rdp-core'], true)
                || isset($roles[$image['role']])
                || ($image['archive'] ?? '') !== 'images/'.$image['role'].'.tar'
                || ! preg_match('/^[a-f0-9]{64}$/D', $image['sha256'] ?? '')
                || ! preg_match('/^sha256:[a-f0-9]{64}$/D', $image['image_id'] ?? '')
                || ! preg_match('~^[a-z0-9][a-z0-9._/:@-]{1,200}$~D', $image['ref'] ?? '')
                || ! isset($names[$image['archive']])) {
                throw new \InvalidArgumentException('Invalid runtime image declaration.');
            }
            $roles[$image['role']] = true; $expected[] = $image['archive'];
        }
        sort($expected); $actual = array_keys($names); sort($actual);
        if ($actual !== $expected) { throw new \InvalidArgumentException('Runtime ZIP must contain only manifest and image archives.'); }
    }
}
