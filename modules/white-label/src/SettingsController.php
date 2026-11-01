<?php

declare(strict_types=1);

namespace Perysee\Modules\WhiteLabel;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    public function save(Request $request, Branding $branding): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:40', 'regex:/^[^<>\p{Cc}]+$/u'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.regex' => 'The name cannot contain angle brackets or control characters.', 'accent.regex' => 'The accent must be a color such as #5B21FF.']);
        $branding->saveText($data['name'] ?? null, $data['accent'] ?? null);

        return $this->done('Branding saved.');
    }

    public function reset(Request $request, Branding $branding): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $branding->reset();

        return $this->done('Branding reset to the Perysee defaults.');
    }

    public function uploadLogo(Request $request, Branding $branding): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $request->validate(['logo' => ['required', 'file', 'max:256', Rule::file()->types(['png', 'jpg', 'jpeg', 'webp', 'svg'])]]);
        $file = $request->file('logo');
        $mime = $file->getMimeType();
        abort_unless(isset(self::LOGO_TYPES[$mime]), 422, 'Unsupported logo type.');
        $branding->removeLogo();
        $name = 'logo.'.self::LOGO_TYPES[$mime];
        Storage::disk('local')->putFileAs(Branding::DIRECTORY, $file, $name);
        $branding->saveLogo($name, $mime);

        return $this->done('Logo updated.');
    }

    public function removeLogo(Request $request, Branding $branding): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        $branding->removeLogo();

        return $this->done('Logo removed.');
    }

    /**
     * Served without a session so the sign-in page can show it. The restrictive headers matter for SVG:
     * an image loaded in an <img> cannot run scripts, and opening the URL directly is sandboxed.
     */
    public function logo(Branding $branding): StreamedResponse
    {
        $logo = $branding->logo();
        abort_unless($logo, 404);

        return Storage::disk('local')->response(Branding::DIRECTORY.'/'.$logo['file'], null, [
            'Content-Type' => $logo['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()?->is_admin, 403);
    }

    private function done(string $message): RedirectResponse
    {
        return redirect()->route('modules.settings', 'white-label')->with('status', $message);
    }
}
