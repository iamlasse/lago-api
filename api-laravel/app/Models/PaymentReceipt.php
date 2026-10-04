<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ActiveStorage;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `payment_receipts` (Rails' PaymentReceipt).
 *
 * NUMBER ASSIGNMENT — the DB trigger, not the model. Rails assigns the
 * receipt number through the frozen-schema trigger
 * `set_payment_receipt_number()` (`before_payment_receipt_insert`): when a
 * row is inserted with number IS NULL, the trigger resolves the customer
 * through the payment (invoice payables via invoices.customer_id, payment
 * request payables via payment_requests.customer_id), takes the customer's
 * slug as the prefix and atomically increments
 * `customers.payment_receipt_counter`, producing
 * `<slug>-RCPT-<counter, zero-padded to >= 6 digits>` — identical to the
 * port, since the migration loads the frozen structure.sql verbatim and the
 * trigger fires on every INSERT. Rails' app/models/payment_receipt.rb holds
 * no number logic either. (The sequence Rails relies on is the same one
 * behind invoices: the counter lives on the customer row, so two concurrent
 * inserts serialize on the UPDATE ... RETURNING.)
 *
 * Documents: `file` (PDF) and `xml_file` attachments live in the
 * active_storage tables, accessed through App\Support\ActiveStorage (see
 * fileUrl() / xmlUrl() — Rails' has_one_attached :file / :xml_file plus the
 * file_url / xml_url helpers).
 */
#[Fillable([
    'number',
    'payment_id',
    'organization_id',
    'billing_entity_id',
])]
#[Table(name: 'payment_receipts')]
class PaymentReceipt extends BaseModel
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: belongs_to :billing_entity. */
    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Rails: delegate :customer, to: :payment. */
    public function customer(): ?Customer
    {
        return $this->payment->payable?->customer;
    }

    /** Rails: file_url — nil while no PDF is attached. */
    public function fileUrl(): ?string
    {
        return ActiveStorage::url(ActiveStorage::blob($this, ActiveStorage::FILE));
    }

    /** Rails: xml_url — nil while no XML is attached. */
    public function xmlUrl(): ?string
    {
        return ActiveStorage::url(ActiveStorage::blob($this, ActiveStorage::XML_FILE));
    }

    /** Rails: file.attached? */
    public function hasFile(): bool
    {
        return ActiveStorage::blob($this, ActiveStorage::FILE) !== null;
    }

    /** Rails: xml_file.attached? */
    public function hasXmlFile(): bool
    {
        return ActiveStorage::blob($this, ActiveStorage::XML_FILE) !== null;
    }
}
