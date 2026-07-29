<?php

use App\Models\SalescallImage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::redirect('/', '/app');
// Route::get('/', function () {
//     return view('welcome');
// });

Route::prefix('download')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->group(function () {
        Route::get('/', function () {
            $logoPath = public_path('nativephp-icon-web.png');

            return view('download', [
                'data' => [
                    'title' => 'Laravel',
                    'logo' => asset('nativephp-icon-web.png').'?v='.filemtime($logoPath),
                    'download_link' => route('download.manifest'),
                    'apk_link' => route('download.apk'),
                ],
            ]);
        })->name('download.page');

        Route::get('/manifest.plist', function () {
            $ipaUrl = route('download.ipa');
            $iconUrl = route('download.icon');

            $manifest = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>items</key>
    <array>
        <dict>
            <key>assets</key>
            <array>
                <dict>
                    <key>kind</key>
                    <string>software-package</string>
                    <key>url</key>
                    <string>{$ipaUrl}</string>
                </dict>
                <dict>
                    <key>kind</key>
                    <string>display-image</string>
                    <key>url</key>
                    <string>{$iconUrl}</string>
                </dict>
                <dict>
                    <key>kind</key>
                    <string>full-size-image</string>
                    <key>url</key>
                    <string>{$iconUrl}</string>
                </dict>
            </array>
            <key>metadata</key>
            <dict>
                <key>bundle-identifier</key>
                <string>com.kaisa.ommc2027tablet</string>
                <key>bundle-version</key>
                <string>4</string>
                <key>kind</key>
                <string>software</string>
                <key>title</key>
                <string>Laravel</string>
            </dict>
        </dict>
    </array>
</dict>
</plist>
PLIST;

            return response($manifest, 200, [
                'Content-Type' => 'application/x-plist',
            ]);
        })->name('download.manifest');

        Route::get('/NativePHP.ipa', function (): BinaryFileResponse {
            $path = storage_path('app/private/nativephp-download/NativePHP.ipa');

            abort_unless(file_exists($path), 404);

            return response()->file($path, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="NativePHP.ipa"',
            ]);
        })->name('download.ipa');

        Route::get('/NativePHP.apk', function (): BinaryFileResponse {
            $path = storage_path('app/private/nativephp-download/NativePHP.apk');

            abort_unless(file_exists($path), 404);

            return response()->file($path, [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Content-Disposition' => 'inline; filename="NativePHP.apk"',
            ]);
        })->name('download.apk');

        Route::get('/nativephp-icon.png', function (): BinaryFileResponse {
            $path = storage_path('app/private/nativephp-download/nativephp-icon.png');

            abort_unless(file_exists($path), 404);

            return response()->file($path, [
                'Content-Type' => 'image/png',
            ]);
        })->name('download.icon');

        Route::get('/nativephp-icon-android.png', function (): BinaryFileResponse {
            $path = storage_path('app/private/nativephp-download/nativephp-icon-android.png');

            abort_unless(file_exists($path), 404);

            return response()->file($path, [
                'Content-Type' => 'image/png',
            ]);
        })->name('download.icon.android');
    });

Route::middleware('auth')->get('salescall-image/{id}', function ($id) {
    $image = SalescallImage::findOrFail($id);

    abort_unless(file_exists($image->local_path), 404);

    return response()->file($image->local_path, [
        'Cache-Control' => 'private, max-age=3600',
    ]);
});
