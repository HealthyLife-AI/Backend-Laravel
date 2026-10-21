<?php

namespace App\Http\Controllers\Api\HealthRecords;

use App\Http\Controllers\Controller;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use App\Support\ConditionCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Clinical batch 1, C2: the nutritionist's structured conditions. Never
 * reachable by the patient (the route group is clients.*, nutritionist
 * permissions) and no proposal kind exists for them.
 */
class ClientConditionsController extends Controller
{
    public function __construct(private readonly HealthRecordService $records) {}

    public function index(Subscriber $subscriber): JsonResponse
    {
        $this->own($subscriber);

        return response()->json([
            'data' => $subscriber->conditions()->get()->map(fn (PatientCondition $c) => self::present($c))->values(),
            // The one list of codes and Arabic labels, so no client keeps its own copy.
            'available' => collect(ConditionCodes::CODES)->map(fn (string $code) => ['code' => $code, 'label' => ConditionCodes::LABELS_AR[$code]])->values(),
        ]);
    }

    /**
     * Clinical batch 1, D2: the ready-made tips for this patient's conditions
     * (plus the general one), from config/patient_tips.php. Suggestions only;
     * the plan designer inserts the ones she clicks and she edits them.
     */
    public function tips(Subscriber $subscriber): JsonResponse
    {
        $this->own($subscriber);

        $codes = $subscriber->conditions()->pluck('code')->unique()->values();
        $seen = [];
        $conditions = $codes->map(function (string $code) use (&$seen) {
            // Several diabetes codes share one list: show each line once.
            $tips = array_values(array_diff((array) config("patient_tips.{$code}", []), $seen));
            $seen = array_merge($seen, $tips);

            return ['code' => $code, 'label' => ConditionCodes::LABELS_AR[$code], 'tips' => $tips];
        })->filter(fn (array $c) => $c['tips'] !== [])->values();

        return response()->json(['general' => array_values((array) config('patient_tips.general', [])), 'conditions' => $conditions]);
    }

    public function store(Subscriber $subscriber, Request $request): JsonResponse
    {
        $this->own($subscriber);
        $condition = $this->records->addCondition($subscriber, $this->records->validated('condition', $request->all()), $request->user());

        return response()->json(self::present($condition), 201);
    }

    public function update(Subscriber $subscriber, string $condition, Request $request): JsonResponse
    {
        $item = $this->condition($subscriber, $condition);

        return response()->json(self::present($this->records->updateCondition($item, $this->records->validated('condition', $request->all()))));
    }

    public function destroy(Subscriber $subscriber, string $condition): Response
    {
        $this->records->removeCondition($this->condition($subscriber, $condition));

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    public static function present(PatientCondition $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'label' => $c->label(), 'other_text' => $c->other_text, 'note' => $c->note];
    }

    private function own(Subscriber $subscriber): void
    {
        abort_unless($subscriber->belongsToCaller(), 404);
    }

    private function condition(Subscriber $subscriber, string $id): PatientCondition
    {
        $this->own($subscriber);

        return PatientCondition::query()->where('subscriber_id', $subscriber->id)->findOrFail($id);
    }
}
