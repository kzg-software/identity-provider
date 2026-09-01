<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'auth_source' => 'local',
        ];
    }

    /**
     * is_admin wird aus den Rollen abgeleitet (User::booted). Wer die
     * Fabrik mit ['is_admin' => true] aufruft, soll trotzdem ein
     * Administrator werden - also die Rolle mit hinterlegen.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            if ($user->is_admin && ! $user->hasRole('admin')) {
                $user->grantManualRole('admin');
            }
        });
    }

    public function admin(): static
    {
        return $this->state(fn () => ['manual_roles' => ['admin']]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
