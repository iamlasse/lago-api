<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ActiveStorageController;

Route::get('/', function () {
    return view('welcome');
});

// ActiveStorage blob serving at the Rails URL shape (see
// App\Http\Controllers\ActiveStorageController for the signed-id deviation).
Route::get('/rails/active_storage/blobs/redirect/{blobId}/{filename}', [ActiveStorageController::class, 'show'])
    ->name('active_storage.blobs.redirect');
