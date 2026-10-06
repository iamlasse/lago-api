{{-- Port of Rails' app/views/templates/credit_notes/self_billed.slim — the
     self-billed variant differs from the base credit-note layout only in the
     self-billed footer; the ported template reuses the credit-note layout and
     appends the self-billed footer text. TODO(port): the full self-billed
     layout fidelity (the issued-by headline block). --}}
@php($selfBilledFooter = true)
@include('documents.credit_notes.credit_note')
