<?php

namespace Tests\Feature\Clients;

use App\Models\Subscriber;
use App\Models\User;
use App\Support\Phone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * A phone that repeats its country code ("+970970…") is what pasting a
 * full number into a field that already has the code selected produces.
 * The API now refuses it, and a read-only command lists any that were
 * stored before it did.
 */
class DoubledCountryCodeTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function add(string $phone, User $nutritionist): TestResponse
    {
        return $this->postJson('/api/v1/clients', ['name' => 'مريض تجريبي', 'phone' => $phone, 'goal' => 'weight_loss'], $this->bearerFor($nutritionist));
    }

    /** @return array<string, array{0: string}> */
    public static function doubled(): array
    {
        return [
            'palestine' => ['+970970599123456'],
            'israel prefix' => ['+972972501234567'],
            'saudi' => ['+966966501234567'],
            'yemen' => ['+967967771234567'],
            'written with 00' => ['00970970599123456'],
            'with spaces and dashes' => ['+970 970-599 123 456'],
            'eastern digits' => ['+٩٧٠٩٧٠٥٩٩١٢٣٤٥٦'],
        ];
    }

    #[DataProvider('doubled')]
    public function test_a_doubled_country_code_is_rejected_with_a_clear_message(string $phone): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $response = $this->add($phone, $nutritionist)->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertStringContainsString('repeats the country code', $response->json('errors.phone.0'));
        $this->assertDatabaseCount('subscribers', 0);
    }

    public function test_a_normal_number_is_still_accepted(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        foreach (['+970599123456', '+966501234567', '+967771234567', '+962791234567', '+201012345678', '+447911123456'] as $phone) {
            $this->add($phone, $nutritionist)->assertCreated();
        }
    }

    /** The check keys on the number repeating ITS OWN prefix, not on any repeated digits. */
    public function test_repeated_digits_that_are_not_a_doubled_code_pass(): void
    {
        $this->assertNull(Phone::doubledCountryCode('+970599970123'));
        $this->assertNull(Phone::doubledCountryCode('+966555555555'));
        $this->assertNull(Phone::doubledCountryCode('+9709'));
        $this->assertNull(Phone::doubledCountryCode('970970599123456'), 'not international: another rule rejects it');
        $this->assertSame('970', Phone::doubledCountryCode('+970970599123456'));
        $this->assertSame('20', Phone::doubledCountryCode('+2020123456789'));
    }

    public function test_the_command_lists_doubled_numbers_and_changes_nothing(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $bad = $this->patientWithPhone($nutritionist, '+970970599123456');
        $this->patientWithPhone($nutritionist, '+970599123456');

        $before = DB::table('users')->orderBy('id')->get()->toArray();
        $updatedBefore = DB::table('subscribers')->pluck('updated_at', 'id')->all();

        $this->assertSame(0, Artisan::call('phones:list-doubled'));
        $output = Artisan::output();

        // The doubled one is listed, with what it would read as; the good one is not.
        $this->assertStringContainsString('+970970599123456', $output);
        $this->assertSame(1, substr_count($output, '+970970599123456'));
        $this->assertStringContainsString('1 found. Nothing was changed.', $output);

        $this->assertEquals($before, DB::table('users')->orderBy('id')->get()->toArray());
        $this->assertEquals($updatedBefore, DB::table('subscribers')->pluck('updated_at', 'id')->all());
        $this->assertSame('+970970599123456', User::find($bad->user_id)->phone);
    }

    public function test_the_command_says_so_when_there_is_nothing_to_report(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $this->patientWithPhone($nutritionist, '+970599123456');

        $this->artisan('phones:list-doubled')
            ->expectsOutputToContain('No stored phone has a doubled country code.')
            ->assertSuccessful();
    }

    private function patientWithPhone(User $nutritionist, string $phone): Subscriber
    {
        $client = User::factory()->client($nutritionist)->create(['phone' => $phone]);

        return Subscriber::factory()->create(['user_id' => $client->id, 'nutritionist_id' => $nutritionist->id]);
    }
}
