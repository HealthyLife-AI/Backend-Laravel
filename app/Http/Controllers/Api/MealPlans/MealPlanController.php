<?php

namespace App\Http\Controllers\Api\MealPlans;

use App\Http\Controllers\Controller;
use App\Http\Requests\MealPlans\StoreMealPlanRequest;
use App\Http\Requests\MealPlans\UpdateMealPlanRequest;
use App\Http\Resources\MealPlanResource;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\MealPlans\AiDraftPlanService;
use App\Services\MealPlans\MealPlanService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * S3-01/S3-02/S3-07: the nutritionist-facing plan designer — create,
 * view, edit, and activate a client's plan. `$subscriber` and
 * `$mealPlan` are both route-model-bound but neither is isolated by that
 * alone (same caveat as every other route-bound model in this app — see
 * `Subscriber`'s docblock); every action re-checks ownership explicitly.
 */
class MealPlanController extends Controller
{
    public function __construct(
        private readonly MealPlanService $plans,
        private readonly AiDraftPlanService $aiDraft,
    ) {}

    /** Dotted paths load their intermediate relations too — meals and items don't need listing separately. */
    private const EAGER_LOAD = ['meals.items.food', 'meals.items.alternatives.food'];

    public function index(Subscriber $subscriber): AnonymousResourceCollection
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $plans = $subscriber->mealPlans()->latest()->get()->load(self::EAGER_LOAD);

        return MealPlanResource::collection($plans);
    }

    public function store(Subscriber $subscriber, StoreMealPlanRequest $request): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $plan = $this->plans->create($subscriber, $request->validated(), $request->user());

        return new MealPlanResource($plan->load(self::EAGER_LOAD));
    }

    public function show(Subscriber $subscriber, MealPlan $mealPlan): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        return new MealPlanResource($mealPlan->load(self::EAGER_LOAD));
    }

    /** S3-07: also how the nutritionist edits an AI draft before approving it. */
    public function update(Subscriber $subscriber, MealPlan $mealPlan, UpdateMealPlanRequest $request): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        if (isset($request->validated()['start_date'])) {
            $mealPlan->update(['start_date' => $request->validated()['start_date']]);
        }
        $this->plans->replaceMeals($mealPlan, $request->validated()['meals']);

        // Only the plan the patient is following; editing a draft tells them nothing.
        if ($mealPlan->status === 'active') {
            $this->notifyPatient($subscriber, $mealPlan, 'plan_updated');
        }

        return new MealPlanResource($mealPlan->load(self::EAGER_LOAD));
    }

    /**
     * BR-6/BR-10: the only way a plan (hand-built or AI draft) ever
     * becomes what the client sees.
     */
    public function activate(Subscriber $subscriber, MealPlan $mealPlan): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        $this->plans->activate($mealPlan);
        $this->notifyPatient($subscriber, $mealPlan, 'plan_activated');

        return new MealPlanResource($mealPlan->refresh()->load(self::EAGER_LOAD));
    }

    /**
     * Activation and later edits of the same plan share one dedupe key, so
     * "activate, then edit two minutes later" is one notification.
     */
    private function notifyPatient(Subscriber $subscriber, MealPlan $plan, string $type): void
    {
        $user = $subscriber->user()->first();

        if ($user !== null) {
            app(NotificationService::class)->notify($user, 'plan', $type, ['plan_id' => $plan->id], "plan:{$plan->id}");
        }
    }

    /** F-5 (PRD, P1): "Suggest a starting plan." Always a draft — see AiDraftPlanService. */
    public function generateAiDraft(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $plan = $this->aiDraft->generateDraft($subscriber, request()->user());

        // An allergy the patient proposed is not used until approved: say so before the plan is approved.
        $warnings = app(HealthRecordService::class)->hasPendingAllergyProposal($subscriber) ? ['pending_allergy_proposal'] : [];

        $response = (new MealPlanResource($plan->load(self::EAGER_LOAD)))->response();
        $response->setData(['warnings' => $warnings] + $response->getData(true));

        return $response;
    }
}
