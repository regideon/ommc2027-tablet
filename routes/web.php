<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');
// Route::get('/', function () {
//     return view('welcome');
// });

Route::middleware('auth')->get('salescall-image/{id}', function ($id) {
    $image = \App\Models\SalescallImage::findOrFail($id);

    abort_unless(file_exists($image->local_path), 404);

    return response()->file($image->local_path, [
        'Cache-Control' => 'private, max-age=3600',
    ]);
});
