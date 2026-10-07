<?php

use App\Support\AllergyGroups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The patient's goal, medications and allergies as structured records,
     * plus the patient's proposals to change them (nothing a patient
     * proposes applies until the nutritionist approves it).
     *
     * Backfill: every patient gets a goal from subscribers.goal
     * (health_monitoring -> health_energy; that column is left as it is), and
     * the JSON health_profiles.medications / allergies become approved items.
     * Allergies are mapped to the nine groups by keyword, else kept as
     * "other" with their text, all classed confirmed_allergy (the safest).
     * The JSON columns stay, rewritten from these tables after every change.
     */
    public function up(): void
    {
        Schema::create('patient_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained('subscribers')->cascadeOnDelete();
            $table->enum('goal_type', ['weight_loss', 'weight_gain', 'muscle_gain', 'weight_maintenance', 'health_energy', 'medical_condition', 'other']);
            $table->decimal('target_weight_kg', 5, 2)->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedTinyInteger('training_days_per_week')->nullable();
            $table->enum('training_level', ['beginner', 'intermediate', 'advanced'])->nullable();
            $table->enum('training_type', ['strength', 'cardio', 'mixed', 'sports'])->nullable();
            $table->string('details', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('patient_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('dose', 100)->nullable();
            $table->string('frequency', 100)->nullable();
            $table->enum('timing', ['before', 'with', 'after', 'empty_stomach', 'any'])->default('any');
            $table->string('reason', 200)->nullable();
            $table->enum('status', ['ongoing', 'until'])->default('ongoing');
            $table->date('until_date')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->enum('group', ['tree_nuts', 'peanuts', 'milk_lactose', 'egg', 'wheat_gluten', 'sesame', 'fish', 'shellfish', 'soy', 'other']);
            $table->string('other_text', 100)->nullable();
            $table->enum('class', ['confirmed_allergy', 'intolerance', 'avoid'])->default('confirmed_allergy');
            $table->string('note', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('profile_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->enum('kind', ['goal', 'medication', 'allergy']);
            $table->enum('action', ['add', 'edit', 'remove']);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'withdrawn'])->default('pending');
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['subscriber_id', 'status']);
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();
        $goalMap = ['weight_loss' => 'weight_loss', 'weight_gain' => 'weight_gain', 'weight_maintenance' => 'weight_maintenance', 'health_monitoring' => 'health_energy'];

        DB::table('subscribers')->select('id', 'goal')->orderBy('id')->chunkById(500, function ($rows) use ($now, $goalMap) {
            DB::table('patient_goals')->insert($rows->map(fn ($r) => [
                'subscriber_id' => $r->id,
                'goal_type' => $goalMap[$r->goal] ?? 'other',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        DB::table('health_profiles')->select('id', 'subscriber_id', 'medications', 'allergies')->orderBy('id')->chunkById(500, function ($rows) use ($now) {
            foreach ($rows as $row) {
                foreach (json_decode((string) $row->medications, true) ?: [] as $med) {
                    if (! is_array($med) || blank($med['name'] ?? null)) {
                        continue;
                    }
                    DB::table('patient_medications')->insert([
                        'subscriber_id' => $row->subscriber_id,
                        'name' => mb_substr((string) $med['name'], 0, 100),
                        'dose' => filled($med['dose'] ?? null) ? mb_substr((string) $med['dose'], 0, 100) : null,
                        'frequency' => filled($med['schedule'] ?? null) ? mb_substr((string) $med['schedule'], 0, 100) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $seen = [];
                foreach (json_decode((string) $row->allergies, true) ?: [] as $text) {
                    if (! is_string($text) || trim($text) === '') {
                        continue;
                    }
                    $group = AllergyGroups::fromText($text);
                    $key = $group === 'other' ? 'other:'.mb_strtolower(trim($text)) : $group;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    DB::table('patient_allergies')->insert([
                        'subscriber_id' => $row->subscriber_id,
                        'group' => $group,
                        'other_text' => $group === 'other' ? mb_substr(trim($text), 0, 100) : null,
                        'class' => 'confirmed_allergy',
                        'note' => $group === 'other' ? null : mb_substr(trim($text), 0, 300),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_proposals');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('patient_medications');
        Schema::dropIfExists('patient_goals');
    }
};
