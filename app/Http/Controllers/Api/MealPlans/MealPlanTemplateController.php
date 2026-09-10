<?php

namespace App\Http\Controllers\Api\MealPlans;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealPlanResource;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\MealPlans\MealPlanService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * S3-03 / FR-15: save a plan as a reusable template, and reuse one for a
 * different client. Templates aren't tied to a subscriber (see the
 * `meal_plans` migration), so isolation runs through
 * `MealPlan::belongsToCaller()`'s template branch (`created_by`), not
 * through a subscriber relation.
 */
class MealPlanTemplateController extends Controller
{
    public function __construct(private readonly MealPlanService $plans) {}

    private const EAGER_LOAD = ['meals.items.food', 'meals.items.alternatives.food'];

    /** This nutritionist's own templates. */
    public function index(): AnonymousResourceCollection
    {
        $templates = MealPlan::query()
            ->where('is_template', true)
            ->where('created_by', request()->user()->id)
            ->latest()
            ->get()
            ->load(self::EAGER_LOAD);

        return MealPlanResource::collection($templates);
    }

    public function store(Subscriber $subscriber, MealPlan $mealPlan): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        $template = $this->plans->saveAsTemplate($mealPlan, request()->user());

        return new MealPlanResource($template->load(self::EAGER_LOAD));
    }

    /** Clones the template into a brand-new draft plan for `$subscriber` — still needs an explicit `activate()`. */
    public function apply(MealPlan $mealPlan, Subscriber $subscriber): MealPlanResource
    {
        abort_unless($mealPlan->belongsToCaller(), 404);
        abort_unless($mealPlan->is_template, 404);
        abort_unless($subscriber->belongsToCaller(), 404);

        $plan = $this->plans->applyTemplate($mealPlan, $subscriber, request()->user());

        return new MealPlanResource($plan->load(self::EAGER_LOAD));
    }
}
