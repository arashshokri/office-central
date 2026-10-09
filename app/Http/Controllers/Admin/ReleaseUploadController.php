<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Services\ReleaseUploadService;
use Illuminate\Http\Request;

final class ReleaseUploadController extends Controller
{
    public function store(Request $request, ReleaseUploadService $uploads)
    {
        $data = $request->validate(['filename' => ['required', 'string', 'max:200', 'regex:/^[^\/\\\\\x00-\x1F]+\.zip$/iD'],
            'size' => 'required|integer|min:1|max:'.ReleaseUploadService::MAX_BYTES,
            'release_id' => 'nullable|integer|exists:releases,id']);
        if ($data['release_id'] ?? null) {
            $release = Release::findOrFail($data['release_id']);
            abort_if($release->status->value === 'published', 422, __('ui.published_release_immutable'));
        }

        return response()->json($uploads->create($request->user()->id, $data['filename'], (int) $data['size'],
            isset($data['release_id']) ? (int) $data['release_id'] : null));
    }

    public function chunk(Request $request, string $upload, ReleaseUploadService $uploads)
    {
        $data = $request->validate(['offset' => 'required|integer|min:0|max:'.ReleaseUploadService::MAX_BYTES,
            'chunk' => 'required|file|max:256']);

        return response()->json($uploads->append($upload, $request->user()->id, (int) $data['offset'], $request->file('chunk')));
    }

    public function destroy(Request $request, string $upload, ReleaseUploadService $uploads)
    {
        $uploads->discard($upload, $request->user()->id);

        return response()->json(['discarded' => true]);
    }
}
