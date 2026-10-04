{{-- Port of Rails' v4/_eu_tax_management.slim. --}}
@if (filled($ctx->billingEntity()?->eu_tax_management))
    @php($appliedTaxes = $invoice->appliedTaxes()->get())
    @if ($appliedTaxes->isNotEmpty())
        @php($appliedTaxCodes = $appliedTaxes->pluck('tax_code')->all())
        <p class="body-3 mb-24">
            @if (in_array('lago_eu_tax_exempt', $appliedTaxCodes, true))
                @if ($ctx->billingEntity()->country === 'FR')
                    {{ __('invoice.taxes.fr_tax_exempt') }}
                @else
                    {{ __('invoice.taxes.tax_exempt') }}
                @endif
            @endif
            @if (in_array('lago_eu_reverse_charge', $appliedTaxCodes, true))
                {{ __('invoice.taxes.reverse_charge') }}
            @endif
        </p>
    @endif
@endif
