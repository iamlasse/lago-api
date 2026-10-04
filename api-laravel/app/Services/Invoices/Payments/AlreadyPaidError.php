<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use RuntimeException;

/**
 * Port of Rails' Invoices::Payments::AlreadyPaidError — raised right
 * before an off-session charge when the payable has already been settled by
 * another payment path (e.g. a hosted checkout session). Signals the caller
 * to abort the charge without delivering an error webhook or scheduling a
 * retry.
 */
class AlreadyPaidError extends RuntimeException {}
