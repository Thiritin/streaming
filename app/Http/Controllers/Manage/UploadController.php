<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One endpoint for every file a manage form can attach.
 *
 * The stored path is flashed back, and the form field then submits it as an ordinary
 * field value, so uploads stay inside the Inertia request cycle - no JSON API.
 */
class UploadController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $purposes = config('manage.uploads');

        $request->validate([
            'purpose' => ['required', Rule::in(array_keys($purposes))],
        ]);

        $config = $purposes[$request->string('purpose')->toString()];

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', $config['mimes']),
                'max:'.$config['max'],
            ],
        ]);

        $file = $request->file('file');

        $name = $config['preserve_filename']
            ? Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension()
            : Str::random(40).'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($config['directory'], $name, [
            'disk' => $config['disk'],
            'visibility' => $config['visibility'],
        ]);

        Toast::put('upload', [
            'purpose' => $request->string('purpose')->toString(),
            'path' => $path,
            'url' => $this->previewUrl($config['disk'], $config['visibility'], $path),
        ]);

        Toast::flashSuccess('File uploaded', $name);

        return back();
    }

    private function previewUrl(string $disk, string $visibility, string $path): ?string
    {
        $storage = Storage::disk($disk);

        if ($visibility === 'private') {
            // Local and public drivers have the method but throw when they cannot sign.
            try {
                return $storage->temporaryUrl($path, now()->addMinutes(30));
            } catch (\Throwable) {
                return null;
            }
        }

        return $storage->url($path);
    }
}
