<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\OfficeReleaseReadiness;
use Illuminate\Console\Command;

final class IndexOfficeSources extends Command
{
    protected $signature = 'office:index-source-releases';

    protected $description = 'Validate retained Office source archives for signed source updates without changing their payloads';

    public function handle(OfficeReleaseReadiness $readiness): int
    {
        $checked = $validated = $failed = 0;
        $this->info('Central '.trim(file_get_contents(base_path('VERSION'))).' — source package inspection');
        Release::with('product')->chunkById(20, function ($rows) use ($readiness, &$checked, &$validated, &$failed) {
            foreach ($rows as $release) {
                if ($release->runtime_manifest || $release->source_manifest) {
                    continue;
                }
                $checked++;
                $error = $readiness->inspect($release);
                $label = '#'.$release->id.' '.$release->product?->slug.' / '.$release->version;
                if ($error || ! $release->source_manifest) {
                    $failed++;
                    $this->warn($label.' — not ready: '.($error ?? 'No verified Office manifest.'));
                } else {
                    $validated++;
                    $this->info($label.' — verified Office source.');
                }
            }
        });
        $this->info("Checked: {$checked}; validated: {$validated}; not ready: {$failed}.");
        if ($checked === 0) {
            $this->comment('No unindexed releases found. Existing verified packages were left unchanged.');
        }

        return self::SUCCESS;
    }
}
