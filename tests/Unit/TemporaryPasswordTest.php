<?php

namespace Tests\Unit;

use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    public function test_every_generated_password_meets_the_shape_and_the_password_policy(): void
    {
        $seen = [];

        for ($i = 0; $i < 1000; $i++) {
            $password = TemporaryPassword::generate();
            $seen[$password] = true;

            $this->assertSame(10, strlen($password));
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-np-z2-9]{10}$/', $password, 'no 0 O o 1 l I');
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            // The same rule PUT /me/password applies to a password the patient chooses.
            $this->assertTrue(Validator::make(['p' => $password], ['p' => [Password::min(8)->mixedCase()->numbers()]])->passes());
        }

        $this->assertCount(1000, $seen, 'no repeats in 1000 draws');
    }
}
