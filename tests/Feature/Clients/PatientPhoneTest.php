<?php

namespace Tests\Feature\Clients;

use App\Models\User;
use App\Support\Phone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** A5: patient phones are normalised to international format on create. */
class PatientPhoneTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
    }

    private function add(string $phone, string $username = 'sara.k')
    {
        return $this->postJson('/api/v1/clients', ['name' => 'سارة', 'phone' => $phone, 'username' => $username, 'goal' => 'weight_loss'], $this->bearerFor($this->nutritionist));
    }

    public function test_spaces_dashes_and_arabic_digits_are_normalised(): void
    {
        $this->add('+970 59-912 ٣٤٥٦')->assertCreated()->assertJsonPath('client.phone', '+970599123456');
        $this->add('00970 599 123 457', 'sara.two')->assertCreated()->assertJsonPath('client.phone', '+970599123457');
    }

    public function test_a_local_number_without_country_code_is_refused(): void
    {
        $this->add('0599123456')->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->add('+0599123456')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_the_same_number_typed_differently_is_a_duplicate(): void
    {
        $this->add('+970599123456')->assertCreated();
        $this->add('+970 599-123-456', 'sara.two')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    // TEMPORARY: goes with the phone login path.
    public function test_phone_login_accepts_the_number_typed_with_spaces_or_arabic_digits(): void
    {
        $password = $this->add('+970599123456')->json('credentials.password');

        $this->postJson('/api/v1/auth/login', ['phone' => '+970 599 ١٢٣ ٤٥٦', 'password' => $password])->assertOk();
    }

    public function test_a_number_saved_before_normalisation_still_signs_in_as_saved(): void
    {
        $user = User::factory()->create(['phone' => '0599 123 458', 'password' => 'OldPass123', 'nutritionist_id' => $this->nutritionist->id]);
        $user->assignRole('client');

        $this->postJson('/api/v1/auth/login', ['phone' => '0599 123 458', 'password' => 'OldPass123'])->assertOk();
    }

    public function test_clean_helper(): void
    {
        $this->assertSame('+970599123456', Phone::clean(' (+970) 599.123.456 '));
        $this->assertTrue(Phone::isInternational('+970599123456'));
        $this->assertFalse(Phone::isInternational('0599123456'));
    }
}
