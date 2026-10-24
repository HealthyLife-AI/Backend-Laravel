<?php

namespace App\Http\Controllers\Api\MealPlans;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MealPlans\StoreMealPlanRequest;
use App\Http\Requests\MealPlans\UpdateMealPlanRequest;
use App\Http\Requests\MealPlans\UpdatePlanDecisionRequest;
use App\Http\Resources\MealPlanResource;
use App\Models\Food;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\MealPlans\AiDraftPlanService;
use App\Services\FoodPreferences\FoodPreferenceService;
use App\Services\MealPlans\AllergenConflictService;
use App\Services\MealPlans\NutritionWarningService;
use App\Services\MealPlans\MealPlanService;
use App\Services\MealPlans\PlanSummarySuggestionService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        private readonly AllergenConflictService $allergens,
        private readonly NutritionWarningService $nutrition,
    ) {}

    /** Dotted paths load their intermediate relations too — meals and items don't need listing separately. */
    private const EAGER_LOAD = ['meals.items.food', 'meals.items.alternatives.food', 'activities'];

    /**
     * Pilot feedback 2, A: every single-plan response carries the plan's
     * allergen report (conflicts, unverified foods, and whether an active
     * plan holds a confirmed conflict its acknowledgement does not cover).
     * The resource drops all of it for the client role.
     */
    private function present(MealPlan $plan, Subscriber $subscriber, ?array $profile = null, ?array $codes = null): MealPlanResource
    {
        $plan->loadMissing(self::EAGER_LOAD);
        $report = $this->allergens->forPlan($plan, $subscriber, $profile);

        return (new MealPlanResource($plan))->withAllergens(
            $report,
            $this->allergens->hasNewUnacknowledged($plan, $report['conflicts']),
        )->withNutritionWarnings($this->nutrition->forPlan($plan, $subscriber, $codes));
    }

    /**
     * The patient's allergy profile is read once and shared by every plan
     * listed, so a long history is one query rather than one per plan. An
     * archived plan is not followed any more, so it is not scanned.
     */
    public function index(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $plans = $subscriber->mealPlans()->latest()->get()->load(self::EAGER_LOAD);
        $profile = $this->allergens->profile($subscriber);
        $codes = $this->nutrition->codes($subscriber);
        $request = request();

        return response()->json($plans->map(
            fn (MealPlan $plan) => $plan->status === 'archived'
                ? (new MealPlanResource($plan))->resolve($request)
                : $this->present($plan, $subscriber, $profile, $codes)->resolve($request)
        )->values());
    }

    public function store(Subscriber $subscriber, StoreMealPlanRequest $request): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $plan = $this->plans->create($subscriber, $request->validated(), $request->user());

        return $this->present($plan, $subscriber);
    }

    public function show(Subscriber $subscriber, MealPlan $mealPlan): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        return $this->present($mealPlan, $subscriber);
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
        $this->plans->replaceActivities($mealPlan, $request->validated());

        // Only the plan the patient is following; editing a draft tells them nothing.
        if ($mealPlan->status === 'active') {
            $this->notifyPatient($subscriber, $mealPlan, 'plan_updated');
        }

        // The save always succeeds. An edit that brings in a confirmed-allergy
        // food the acknowledgement does not cover is reported, never blocked:
        // `new_unacknowledged_conflicts` makes the dashboard raise the red
        // warning again, so it cannot pass silently.
        return $this->present($mealPlan->refresh(), $subscriber);
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

        return $this->present($copy, $subscriber)->response()->setStatusCode(201);
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

        $before = [$mealPlan->patient_instructions, $this->plans->activitySignature($mealPlan)];

        $this->plans->applyDecision($mealPlan, $request->validated());
        $this->plans->replaceActivities($mealPlan, $request->validated());

        // Clinical batch 1, D4 / Phase 1, A2: instructions and activities are
        // patient-facing, so changing them on the plan in force tells the
        // patient it was updated - the same generic notification and dedupe
        // key as a meal edit, nothing new, and no re-activation
        // (activated_at is untouched).
        $mealPlan->refresh();
        if ($mealPlan->status === 'active' && [$mealPlan->patient_instructions, $this->plans->activitySignature($mealPlan)] !== $before) {
            $this->notifyPatient($subscriber, $mealPlan, 'plan_updated');
        }

        return $this->present($mealPlan->refresh(), $subscriber);
    }

    /**
     * Pilot feedback 2, A3: record that she reviewed the confirmed-allergy
     * conflicts on an ACTIVE plan and keeps them - the way to clear the red
     * warning after an edit, or after an allergy was approved later.
     *
     * Activating again is not an option for that: activation restarts
     * `activated_at` (adherence windows and the no-log clock hang off it)
     * and notifies the patient. This touches neither, and sends nothing.
     */
    public function acknowledgeAllergens(Subscriber $subscriber, MealPlan $mealPlan, Request $request): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        $request->validate(['acknowledge_allergen_conflicts' => ['required', 'accepted']]);

        $conflicts = $this->allergens->forPlan($mealPlan, $subscriber)['conflicts'];
        $this->plans->recordAllergenAcknowledgement($mealPlan, $request->user(), $conflicts);

        return $this->present($mealPlan->refresh(), $subscriber);
    }

    /**
     * Pilot feedback 2, A: the dashboard's inline warning, from the same
     * rules as the plan report. One call covers every food an autocomplete
     * just listed. Nothing is saved.
     */
    public function allergenCheck(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validate([
            'food_ids' => ['required_without:items', 'array', 'min:1', 'max:50'],
            'food_ids.*' => ['integer'],
            // The same foods with the quantity the caller means to plan, so a
            // high GI of a small amount is not flagged.
            'items' => ['required_without:food_ids', 'array', 'min:1', 'max:50'],
            'items.*.food_id' => ['required', 'integer'],
            'items.*.quantity_grams' => ['nullable', 'numeric', 'min:0', 'max:5000'],
        ]);

        $wanted = isset($data['items'])
            ? array_map(fn (array $i) => ['id' => (int) $i['food_id'], 'grams' => isset($i['quantity_grams']) ? (float) $i['quantity_grams'] : null], $data['items'])
            : array_map(fn ($id) => ['id' => (int) $id, 'grams' => null], $data['food_ids']);

        $profile = $this->allergens->profile($subscriber);
        $codes = $this->nutrition->codes($subscriber);
        $byId = Food::query()->whereIn('id', array_column($wanted, 'id'))->get()->keyBy('id');
        // Phase 2, B4: the patient's like / dislike per food, for the picker's marks and the row note.
        $preferences = app(FoodPreferenceService::class)->kindsFor($subscriber, array_column($wanted, 'id'));

        $foods = collect($wanted)->filter(fn (array $w) => $byId->has($w['id']))->map(function (array $w) use ($byId, $profile, $codes, $preferences) {
            $food = $byId[$w['id']];

            return [
                'food_id' => $food->id,
                'quantity_grams' => $w['grams'],
                'conflict' => $this->allergens->matchFood($food, $profile),
                'unverified' => $food->allergens === null,
                'preference' => $preferences[$food->id] ?? null,
                // Clinical batch 1, E4: GI / sodium, reviewed foods only.
                'nutrition_warnings' => $this->nutrition->forFood($food, $codes, $w['grams']),
            ];
        })->values();

        return response()->json(['foods' => $foods]);
    }

    /**
     * BR-6/BR-10: the only way a plan (hand-built or AI draft) ever
     * becomes what the client sees.
     */
    public function activate(Subscriber $subscriber, MealPlan $mealPlan, Request $request): MealPlanResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);
        abort_unless($mealPlan->subscriber_id === $subscriber->id, 404);

        $conflicts = $this->allergens->forPlan($mealPlan, $subscriber)['conflicts'];
        $blocking = $this->allergens->confirmed($conflicts);

        // A food that conflicts with a CONFIRMED allergy does not reach a
        // patient on a click. Intolerances and foods to avoid never block:
        // they are shown, and left to her judgement.
        if ($blocking !== [] && ! $request->boolean('acknowledge_allergen_conflicts')) {
            throw new ApiCodeException(
                'This plan contains a food that conflicts with a confirmed allergy. Review it and confirm to activate.',
                'allergen_conflict_unacknowledged',
                422,
                ['allergen_conflicts' => $conflicts],
            );
        }

        $this->plans->activate($mealPlan, $blocking !== [] ? $request->user() : null, $blocking !== [] ? $conflicts : null);
        $this->notifyPatient($subscriber, $mealPlan, 'plan_activated');

        return $this->present($mealPlan->refresh(), $subscriber);
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

        $response = $this->present($plan, $subscriber)->response();
        $response->setData(['warnings' => $warnings] + $response->getData(true));

        return $response;
    }
}
