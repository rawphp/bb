<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        });
    }

    /** The early deletes and the user row go together, or neither does. */
    public function delete(): ?bool
    {
        return DB::transaction(fn () => parent::delete());
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
