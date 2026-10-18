<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'accent',
        'show_decimals',
        'privacy_mode',
        'monthly_income',
        'savings_goal',
        'retire_year',
        'retire_birth_year',
        'retire_contribution',
        'retire_crypto_mode',
        'retire_duration',
        'retire_monthly',
        'retire_index_contributions',
        'retire_inflation',
        'retire_fees',
        'retire_haircut',
        'retire_withdrawal',
        'retire_engine',
        'retire_target_income',
        'retire_spending',
        'retire_housing',
        'reserve_profile',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'apple_id',
        'apple_refresh_token',
    ];

    /**
     * Appka podľa has_password vie, či pri zmazaní účtu pýtať heslo
     * (účty len cez Apple ho nemajú).
     *
     * @var list<string>
     */
    protected $appends = ['has_password', 'apple'];

    protected function hasPassword(): Attribute
    {
        return Attribute::get(fn () => $this->password !== null);
    }

    protected function apple(): Attribute
    {
        return Attribute::get(fn () => $this->apple_id !== null);
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
            'apple_refresh_token' => 'encrypted',
            'show_decimals' => 'boolean',
            'privacy_mode' => 'boolean',
            'monthly_income' => 'decimal:2',
            'savings_goal' => 'decimal:2',
            'retire_year' => 'integer',
            'retire_birth_year' => 'integer',
            'retire_contribution' => 'array',
            'retire_duration' => 'integer',
            'retire_monthly' => 'decimal:2',
            'retire_index_contributions' => 'boolean',
            'retire_inflation' => 'decimal:2',
            'retire_fees' => 'decimal:2',
            'retire_haircut' => 'decimal:2',
            'retire_withdrawal' => 'decimal:2',
            'retire_target_income' => 'decimal:2',
            'retire_spending' => 'decimal:2',
            'retire_housing' => 'decimal:2',
            'reserve_profile' => 'array',
        ];
    }
}
