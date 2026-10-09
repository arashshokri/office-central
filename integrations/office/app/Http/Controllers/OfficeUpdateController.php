<?php

namespace App\Http\Controllers;

use App\Exceptions\OfficeHelperRequestException;
use App\Services\OfficeLicense;
use Illuminate\Http\Request;

final class OfficeUpdateController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'general_manager'], true), 403);
    }

    public function index(Request $request, OfficeLicense $license)
    {
        $this->authorizeAdmin($request);

        return response()->view('office-agent.settings', ['licenseState' => $license->details(), 'decision' => $license->decision(),
            'helperEnabled' => $license->enabled(), 'job' => $license->updateStatus(),
            'installedVersion' => trim(file_get_contents(base_path('VERSION')))])->header('Cache-Control', 'no-store, private');
    }

    public function check(Request $request, OfficeLicense $license)
    {
        $this->authorizeAdmin($request);
        try {
            return response()->json($license->checkUpdates())->header('Cache-Control', 'no-store, private');
        } catch (\RuntimeException $error) {
            return $this->helperError($error);
        }
    }

    public function update(Request $request, OfficeLicense $license)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D'], 'expected_release_id' => ['nullable', 'uuid']]);
        try {
            return response()->json($license->startUpdate($data), 202);
        } catch (\RuntimeException $error) {
            return $this->helperError($error);
        }
    }

    public function status(Request $request, OfficeLicense $license)
    {
        $this->authorizeAdmin($request);

        return response()->json($license->updateStatus())->header('Cache-Control', 'no-store, private');
    }

    private function helperError(\RuntimeException $error)
    {
        if ($error instanceof OfficeHelperRequestException) {
            return response()->json(['message' => $error->getMessage(), 'retry_after' => $error->retryAfter], 429)
                ->withHeaders(['Retry-After' => $error->retryAfter, 'Cache-Control' => 'no-store, private']);
        }

        return response()->json(['message' => $error->getMessage()], 502)->header('Cache-Control', 'no-store, private');
    }
}
