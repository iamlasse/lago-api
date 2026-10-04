<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Serves ActiveStorage blobs at the Rails URL shape
 * (/rails/active_storage/blobs/redirect/:blob_id/:filename) so the
 * serialized file_url / xml_url values resolve.
 *
 * Rails' :blob_id segment is a signed id (base64 blob id + HMAC); the port
 * serves the raw blob UUID — see App\Support\ActiveStorage for the signed-id
 * deviation note. Like Rails, serving is unauthenticated (Lago's own
 * deployment gates these URLs at the ingress).
 */
class ActiveStorageController extends Controller
{
    public function show(string $blobId): Response
    {
        $blob = DB::table('active_storage_blobs')->where('id', $blobId)->first();

        if ($blob === null || ! Storage::disk($blob->service_name)->exists($blob->key)) {
            abort(404);
        }

        return new Response(Storage::disk($blob->service_name)->get($blob->key), 200, [
            'Content-Type' => $blob->content_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$blob->filename.'"',
        ]);
    }
}
