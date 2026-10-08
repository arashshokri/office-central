<?php
namespace App\Http\Controllers;

use App\Services\OfficeLicense;
use Illuminate\Http\Request;

final class OfficeUpdateController extends Controller
{
    private function authorizeAdmin(Request $request): void { abort_unless($request->user()?->role === 'admin', 403); }
    public function index(Request $request, OfficeLicense $license) {
        $this->authorizeAdmin($request);
        return view('office-agent.settings', ['licenseState' => $license->details(), 'decision' => $license->decision(),
            'helperEnabled' => $license->enabled(), 'job' => $license->updateStatus(),
            'installedVersion' => trim(file_get_contents(base_path('VERSION')))]);
    }
    public function check(Request $request, OfficeLicense $license) {
        $this->authorizeAdmin($request);
        try { return response()->json($license->checkUpdates()); }
        catch (\RuntimeException $error) { return response()->json(['message' => $error->getMessage()], 502); }
    }
    public function update(Request $request, OfficeLicense $license) {
        $this->authorizeAdmin($request);
        try { return response()->json($license->startUpdate(), 202); }
        catch (\RuntimeException $error) { return response()->json(['message' => $error->getMessage()], 502); }
    }
    public function status(Request $request, OfficeLicense $license) {
        $this->authorizeAdmin($request);
        return response()->json($license->updateStatus());
    }
}
