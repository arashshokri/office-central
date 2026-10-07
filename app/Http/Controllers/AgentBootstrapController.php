<?php
namespace App\Http\Controllers;

final class AgentBootstrapController
{
    public function __invoke()
    {
        $files = [];
        foreach (['office-agent-linux-amd64', 'office-agent-linux-arm64', 'install-docker.sh'] as $name) {
            $path = public_path('agent/'.$name);
            if (is_file($path)) { $files[$name] = hash_file('sha256', $path); }
        }
        return response()->json(['protocol' => 2, 'version' => config('office.version'),
            'public_key' => config('office.signing_public_key'), 'files' => $files])
            ->header('Cache-Control', 'no-store');
    }
}
