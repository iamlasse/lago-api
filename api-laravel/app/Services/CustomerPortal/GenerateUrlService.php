<?php

declare(strict_types=1);

namespace App\Services\CustomerPortal;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\PortalToken;

/**
 * Port of Rails' CustomerPortal::GenerateUrlService
 * (app/services/customer_portal/generate_url_service.rb) — mints the signed
 * portal token (12h expiry) and hands back the front-end portal URL.
 */
class GenerateUrlService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('url');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        $message = PortalToken::generate($this->customer->id);

        // Rails: "#{Rails.application.config.lago_front_url}/customer-portal/#{message}"
        $result->url = (string) config('lago.front_url').'/customer-portal/'.$message;

        return $result;
    }
}
