<?php

namespace App\Http\Controllers\Api\MealPlans;

use App\Http\Controllers\Controller;
use App\Http\Requests\MealPlans\StoreMealPlanRequest;
use App\Http\Requests\MealPlans\UpdateMealPlanRequest;
use App\Http\Requests\MealPlans\UpdatePlanDecisionRequest;
use App\Http\Resources\MealPlanResource;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\MealPlans\AiDraftPlanService;
use App\Services\MealPlans\MealPlanService;
use App\Services\MealPlans\PlanSummarySuggestionService;
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
        $this->plans->applyDecision($mealPlan, $request->validated());
        $this->plans->replaceMeals($mealPlan, $request->validated()['meals']);

        // Only the plan the patient is following; editing a draft tells them nothing.
        if ($mealPlan->status === 'active') {
            $this->notifyPatient($subscriber, $mealPlan, 'plan_updated');
        }

        return new MealPlanResource($mealPlan->load(self::EAGER_LOAD));
    }

    /**
     * Item E: start the next plan from this one. A new draft, the
     * original untouched — nothing reaches the patient until the copy is
     * activated on its own (BR-6).
     */
    public function duplicate(Subscriber $subscriber, MealPlan $mealPlan): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        $copy = $this->plans->duplicate($mealPlan, request()->user());

        return (new MealPlanResource($copy->load(self::EAGER_LOAD)))->response()->setStatusCode(201);
    }

    /**
     * Item D: a suggested summary for her to edit — never saved here.
     * Every figure in it is computed from the plan itself; the model
     * only names the pattern and adds a follow-up note (see the
     * service), and a reply that invents a number is rejected.
     */
    public function suggestSummary(Subscriber $subscriber, MealPlan $mealPlan, PlanSummarySuggestionService $suggestions): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        return response()->json($suggestions->suggest($mealPlan->load(self::EAGER_LOAD), $subscriber));
    }

    /**
     * Item C: her decision alone — the approved target and the summary —
     * on any plan, including the ACTIVE one, without touching a meal.
     *
     * No BR-6 re-approval is involved: neither field is ever shown to the
     * patient, so changing them cannot change what the patient is
     * following. The plan keeps its status and its `activated_at`, and
     * no new plan is created. Clearing `approved_calories` falls the
     * client back to the formula.
     */
    public function updateDecision(Subscriber $subscriber, MealPlan $mealPlan, UpdatePlanDecisionRequest $request): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        $this->plans->applyDecision($mealPlan, $request->validated());

        return new MealPlanResource($mealPlan->refresh()->load(self::EAGER_LOAD));
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

        // Item C: the figure she has typed into the decision card but not
        // saved yet — the draft is built to the number she is deciding
        // on. Same bounds as the plan requests, for the same reason.
        $approved = request()->validate([
            'approved_calories' => ['sometimes', 'nullable', 'integer', 'between:800,6000'],
        ])['approved_calories'] ?? null;

        $plan = $this->aiDraft->generateDraft($subscriber, request()->user(), $approved);

        // An allergy the patient proposed is not used until approved: say so before the plan is approved.
        $warnings = app(HealthRecordService::class)->hasPendingAllergyProposal($subscriber) ? ['pending_allergy_proposal'] : [];

        $response = (new MealPlanResource($plan->load(self::EAGER_LOAD)))->response();
        $response->setData(['warnings' => $warnings] + $response->getData(true));

        return $response;
    }
}
