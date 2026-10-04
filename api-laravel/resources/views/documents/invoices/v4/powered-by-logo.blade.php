{{-- Port of Rails' v4/_powered_by_logo.slim — hidden when the organization
     has the premium remove_branding_watermark integration enabled. --}}
@php($removeBranding = App\Support\License::premium()
    && in_array('remove_branding_watermark', (array) ($invoice->organization?->premium_integrations ?? []), true))
@if (! $removeBranding)
    <div class="powered-by">
        <span class="body-2">{{ __('invoice.powered_by') }} &nbsp;</span>
        <img src="{{ App\Support\PdfGenerator::PDF_LOGO_FILENAME }}" alt="Lago Logo">
    </div>
@endif
