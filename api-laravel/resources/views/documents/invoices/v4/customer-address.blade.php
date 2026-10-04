{{-- Port of Rails' v4/_customer_address.slim. Rails renders through the
     Addressing::DefaultFormatter (localized address block layout); the port
     answers the same address fields as plain body-2 divs — TODO(port): the
     Addressing formatter's per-country layout. --}}
@php($customer = $invoice->customer)
@if ($customer !== null)
    @if (filled($customer->address_line1))
        <div class="body-2">{{ $customer->address_line1 }}</div>
    @endif
    @if (filled($customer->address_line2))
        <div class="body-2">{{ $customer->address_line2 }}</div>
    @endif
    @if (filled($customer->zipcode) || filled($customer->city))
        <div class="body-2">{{ trim(($customer->zipcode ?? '').' '.($customer->city ?? '')) }}</div>
    @endif
    @if (filled($customer->state))
        <div class="body-2">{{ $customer->state }}</div>
    @endif
    @if (filled($customer->country))
        <div class="body-2">{{ $ctx->countryName($customer->country) }}</div>
    @endif
@endif
