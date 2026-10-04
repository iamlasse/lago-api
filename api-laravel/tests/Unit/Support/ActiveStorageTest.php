<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Support\Str;
use App\Support\ActiveStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Port of Rails' ActiveStorage attachment behavior for invoices
 * (spec coverage of has_one_attached :file / :xml_file and file_url).
 */
it('attaches a blob and attachment row and stores the payload', function (): void {
    Storage::fake('lago_test');

    $invoice = Invoice::factory()->create();

    $blob = ActiveStorage::attach(
        $invoice,
        ActiveStorage::FILE,
        '%PDF-1.4 test',
        $invoice->number.'.pdf',
        'application/pdf',
    );

    expect($blob)->not->toBeNull();
    expect($blob->filename)->toBe($invoice->number.'.pdf');
    expect($blob->content_type)->toBe('application/pdf');
    expect($blob->service_name)->toBe('lago_test');
    expect($blob->byte_size)->toBe(mb_strlen('%PDF-1.4 test'));
    expect($blob->checksum)->toBe(base64_encode(md5('%PDF-1.4 test', true)));
    expect(Str::isUuid($blob->id))->toBeTrue();

    Storage::disk('lago_test')->assertExists($blob->key);
    expect(ActiveStorage::download($blob))->toBe('%PDF-1.4 test');

    $attachment = DB::table('active_storage_attachments')
        ->where('blob_id', $blob->id)
        ->first();

    expect($attachment->name)->toBe('file');
    expect($attachment->record_type)->toBe('Invoice');
    expect($attachment->record_id)->toBe($invoice->id);
});

it('answers attached and url accessors like Rails', function (): void {
    Storage::fake('lago_test');

    config(['lago.api_url' => 'https://api.lago.test']);

    $invoice = Invoice::factory()->create();

    expect($invoice->hasFile())->toBeFalse();
    expect($invoice->fileUrl())->toBeNull();
    expect($invoice->hasXmlFile())->toBeFalse();
    expect($invoice->xmlUrl())->toBeNull();

    ActiveStorage::attach($invoice, ActiveStorage::FILE, 'pdf', 'file.pdf', 'application/pdf');
    ActiveStorage::attach($invoice, ActiveStorage::XML_FILE, '<xml/>', 'file.xml', 'application/xml');

    expect($invoice->hasFile())->toBeTrue();
    expect($invoice->hasXmlFile())->toBeTrue();

    $fileUrl = $invoice->fileUrl();
    $xmlUrl = $invoice->xmlUrl();

    expect($fileUrl)->toStartWith('https://api.lago.test/rails/active_storage/blobs/redirect/');
    expect($fileUrl)->toEndWith('/file.pdf');
    expect($xmlUrl)->toEndWith('/file.xml');
});

it('serves blobs at the Rails redirect path', function (): void {
    // The web middleware group needs an app key even for plain responses;
    // the repo's .env may ship without one in development.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('l', 32))]);

    Storage::fake('lago_test');

    $invoice = Invoice::factory()->create();

    $blob = ActiveStorage::attach($invoice, ActiveStorage::FILE, '%PDF-bytes', 'inv.pdf', 'application/pdf');

    $this->get('/rails/active_storage/blobs/redirect/'.$blob->id.'/inv.pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get('/rails/active_storage/blobs/redirect/'.(string) Str::uuid().'/missing.pdf')
        ->assertNotFound();
});
