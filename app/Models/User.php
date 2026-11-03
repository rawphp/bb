<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * @property bool $coach_on the user's own Coach switch (AGT-020)
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /**
     * A session's token is transient, so not every current token is a PersonalAccessToken.
     *
     * @use HasApiTokens<HasAbilities>
     */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The columns' defaults, so a user made in this request reads them before a refresh. */
    protected $attributes = [
        'coach_on' => true,
    ];

    /**
     * Deleting a user cascades through every user_id (ACC-005). MySQL refuses a cascade that also
     * nulls or restricts a row it is deleting, such as a set of the user's own custom exercise.
     * Whether it does depends on InnoDB's cascade order, so every table with a nulling or restricting
     * key to another user-owned row (sets, off-plan entries, planned foods, recipe ingredients,
     * sessions) has its rows deleted first, while the user still exists.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            foreach (['workout_sessions', 'off_plan_entries', 'planned_foods', 'recipes', 'mesocycles'] as $table) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }

            // Tokens belong to the user through a morph, which no foreign key cascades.
            PersonalAccessToken::ownedBy($user)->delete();

            // The package's old audit rows name the user in their payload, with no foreign key.
            AuditOutboxRow::ownedBy($user)->delete();
        });
    }

    /** The early deletes and the user row go together, or neither does. */
    public function delete(): ?bool
    {
        return DB::transaction(fn () => parent::delete());
    }

    /**
     * Not Sanctum's way: a token made here would have no scope and no client-made ULID.
     * Every token comes from CreateToken, through token_create (CAP-021). The host binds
     * no AuthTokenIssuer, so the package's auth/token and auth/device routes mint none.
     *
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): never
    {
        throw new LogicException('Tokens are made by App\Actions\Tokens\CreateToken, with a scope (CAP-021).');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'coach_on' => 'boolean',
        ];
    }

    /** @return HasMany<PushSubscription, $this> */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Where the Web Push channel sends this user's notifications: only
     * endpoints on a push service still in `webpush.push_services`.
     *
     * @return Collection<int, PushSubscription>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions()->get()
            ->filter(fn (PushSubscription $subscription): bool => PushSubscription::allowedEndpoint($subscription->endpoint))
            ->values();
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasOne<Subscription, $this> the Stripe customer and subscription, with billing on (SUB-021) */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return HasOne<Plan, $this> */
    public function plan(): HasOne
    {
        return $this->hasOne(Plan::class);
    }

    /** @return HasMany<WeighIn, $this> */
    public function weighIns(): HasMany
    {
        return $this->hasMany(WeighIn::class);
    }

    /** @return HasMany<WeighInScheduleEntry, $this> */
    public function weighInScheduleEntries(): HasMany
    {
        return $this->hasMany(WeighInScheduleEntry::class);
    }

    /** @return HasMany<Step, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(Step::class);
    }

    /** @return HasMany<DayTarget, $this> */
    public function dayTargets(): HasMany
    {
        return $this->hasMany(DayTarget::class);
    }

    /** @return HasMany<Phase, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(Phase::class);
    }

    /** @return HasMany<Mesocycle, $this> */
    public function mesocycles(): HasMany
    {
        return $this->hasMany(Mesocycle::class);
    }

    /** @return HasMany<WorkoutSession, $this> */
    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    /** @return HasMany<Food, $this> */
    public function foods(): HasMany
    {
        return $this->hasMany(Food::class);
    }

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** @return HasOne<PendingSignInCode, $this> */
    public function pendingSignInCode(): HasOne
    {
        return $this->hasOne(PendingSignInCode::class);
    }
}
