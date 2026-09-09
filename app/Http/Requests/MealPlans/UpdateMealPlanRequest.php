<?php

namespace App\Http\Requests\MealPlans;

/**
 * Same shape as creating one (PRD F-4: edited as a whole plan, not
 * item-by-item) — including for editing an AI draft before approving it
 * (S3-07): there's no separate "edit a draft" endpoint, this one is it.
 */
class UpdateMealPlanRequest extends StoreMealPlanRequest {}
