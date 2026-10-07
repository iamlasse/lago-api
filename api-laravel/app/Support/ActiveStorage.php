<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BaseModel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Minimal ActiveStorage-compatible storage layer, port of the attachment
 * half of Rails' `has_one_attached` (invoices `file` / `xml_file`, credit
 * notes `file`, ...).
 *
 * Rails stores attachment payloads through ActiveStorage service adapters
 * (config/storage.yml — local disk, S3, GCS selected via LAGO_USE_AWS_S3 /
 * LAGO_USE_GCS) and keeps two rows per attachment: active_storage_blobs +
 * active_storage_attachments. The frozen schema carries both tables, so this
 * port writes the same rows and puts the bytes on the matching Laravel
 * filesystem disk (config/filesystems.php "lago_*" disks).
 *
 * Deviations (documented):
 * - Rails' serving URLs use signed blob ids (base64 of the blob id + HMAC);
 *   the port serves the raw blob UUID at the same
 *   /rails/active_storage/blobs/redirect/:id/:filename path (unauthenticated,
 *   like Rails' public blobs — Lago's invoice blobs are protected by
 *   obscurity there too).
 * - `invoices.file` / `invoices.xml_file` legacy varchar columns (present in
 *   the frozen schema, unused by Rails) stay untouched/NULL.
 */
final class ActiveStorage
{
    /** Record name Rails uses for the invoice PDF attachment. */
    public const string FILE = 'file';

    /** Record name Rails uses for the invoice XML attachment. */
    public const string XML_FILE = 'xml_file';

    /**
     * Port of the ActiveStorage service selection in Rails'
     * config/environments/{development,staging,production}.rb: S3 when
     * LAGO_USE_AWS_S3 (endpoint variant when LAGO_AWS_S3_ENDPOINT is set),
     * GCS when LAGO_USE_GCS, else local disk. Tests use the :test disk
     * (tmp/storage) via APP_ENV=testing.
     */
    public static function diskName(): string
    {
        if (app()->environment('testing')) {
            return 'lago_test';
        }

        $useS3 = env('LAGO_USE_AWS_S3');

        if ($useS3 !== null
            && ($useS3 === 'true' || filter_var($useS3, FILTER_VALIDATE_BOOL))) {
            return filled(env('LAGO_AWS_S3_ENDPOINT')) ? 'lago_s3_compatible' : 'lago_s3';
        }

        $useGcs = env('LAGO_USE_GCS');

        if ($useGcs !== null
            && ($useGcs === 'true' || filter_var($useGcs, FILTER_VALIDATE_BOOL))) {
            return 'lago_gcs';
        }

        return 'lago_local';
    }

    /**
     * Port of `record.file.attach(io:, filename:, content_type:)` — writes
     * the payload to the configured disk and inserts the blob + attachment
     * rows. Returns the blob row (stdClass) for url()/download() callers.
     *
     * `$key` overrides the generated storage key (Rails' `attach(key:)`
     * form — CombinePartsService stores data exports under
     * `data_exports/<id>-<hex5>.<format>`).
     *
     * @param  array{name?: string}|null  $metadata
     */
    public static function attach(
        BaseModel $record,
        string $name,
        string $content,
        string $filename,
        string $contentType,
        ?array $metadata = null,
        ?string $key = null,
    ): object {
        // Rails' attach replaces an existing attachment for the same
        // record/name (ActiveStorage::Attachment#purge then re-attach).
        self::purge($record, $name);

        $key ??= self::generateKey();
        $disk = self::diskName();

        Storage::disk($disk)->put($key, $content);

        $blobId = (string) Str::uuid();

        DB::table('active_storage_blobs')->insert([
            'id' => $blobId,
            'key' => $key,
            'filename' => $filename,
            'content_type' => $contentType,
            'metadata' => $metadata === null ? null : json_encode($metadata),
            'service_name' => $disk,
            'byte_size' => mb_strlen($content),
            'checksum' => base64_encode(md5($content, true)),
            'created_at' => now(),
        ]);

        DB::table('active_storage_attachments')->insert([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'record_type' => class_basename($record),
            'record_id' => $record->id,
            'blob_id' => $blobId,
            'created_at' => now(),
        ]);

        return self::blob($record, $name);
    }

    /**
     * Port of `record.file.attached?` — returns the blob row when an
     * attachment exists, null otherwise (callers treat null as `file.blank?`).
     */
    public static function blob(BaseModel $record, string $name): ?object
    {
        return DB::table('active_storage_attachments')
            ->join('active_storage_blobs', 'active_storage_blobs.id', '=', 'active_storage_attachments.blob_id')
            ->where('active_storage_attachments.record_type', class_basename($record))
            ->where('active_storage_attachments.record_id', $record->id)
            ->where('active_storage_attachments.name', $name)
            ->first(['active_storage_blobs.*', 'active_storage_attachments.name as attachment_name']);
    }

    /**
     * Port of `attachment.purge` — remove the stored payload, the attachment
     * row and the blob row for a record/name pair.
     */
    public static function purge(BaseModel $record, string $name): void
    {
        $attachment = DB::table('active_storage_attachments')
            ->where('record_type', class_basename($record))
            ->where('record_id', $record->id)
            ->where('name', $name)
            ->first();

        if ($attachment === null) {
            return;
        }

        $blob = DB::table('active_storage_blobs')->where('id', $attachment->blob_id)->first();

        DB::table('active_storage_attachments')->where('id', $attachment->id)->delete();

        if ($blob !== null) {
            Storage::disk($blob->service_name)->delete($blob->key);

            DB::table('active_storage_blobs')->where('id', $blob->id)->delete();
        }
    }

    /** Port of `blob.download` — the attachment payload bytes. */
    public static function download(object $blob): string
    {
        return (string) Storage::disk($blob->service_name)->get($blob->key);
    }

    /**
     * Port of Invoice#file_url / Invoice#xml_url (app/models/invoice.rb) —
     * File.join(ENV["LAGO_API_URL"], rails_blob_path(file, host: "void")).
     * With no LAGO_API_URL configured Rails joins against nil (returns the
     * bare path); the port mirrors that by returning the path alone.
     */
    public static function url(?object $blob): ?string
    {
        if ($blob === null) {
            return null;
        }

        $path = sprintf(
            '/rails/active_storage/blobs/redirect/%s/%s',
            $blob->id,
            rawurlencode($blob->filename),
        );

        $apiUrl = config('lago.api_url');

        return $apiUrl === null ? $path : mb_rtrim($apiUrl, '/').$path;
    }

    /** Port of ActiveStorage::Blob key generation (SecureRandom.base58(28)). */
    private static function generateKey(): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

        $key = '';

        for ($i = 0; $i < 28; $i++) {
            $key .= $alphabet[random_int(0, mb_strlen($alphabet) - 1)];
        }

        return $key;
    }
}
