<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `DunningCampaignThreshold` type (port
 * of Rails' Types::DunningCampaignThresholds::Object). Plain columns
 * (id, amountCents, currency) resolve through the snake_case attribute
 * fallback; no computed fields.
 */
class DunningCampaignThreshold {}
