<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Models\Plan;
use Illuminate\Http\Request;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;

/**
 * Port of Rails' Api::V1::Plans::BaseController (app/controllers/api/v1/
 * plans/base_controller.rb) — resolves the plan by `:plan_code` for every
 * nested plan subresource (Rails: before_action :find_plan with find_by!).
 */
abstract class BaseController extends ApiController
{
    protected ?string $resourceName = 'plan';

    /**
     * Port of `find_plan` — RecordNotFound renders the plan_not_found
     * envelope.
     */
    protected function findPlan(Request $request): Plan
    {
        $plan = $this->currentOrganization()
            ->plans()
            ->parents()
            ->where('code', $request->route('plan_code'))
            ->first();

        if ($plan === null) {
            throw new NotFoundException('plan');
        }

        return $plan;
    }
}
