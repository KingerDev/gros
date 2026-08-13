<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'show_decimals' => 'boolean',
            'privacy_mode' => 'boolean',
            'monthly_income' => 'decimal:2',
            'savings_goal' => 'decimal:2',
            'retire_year' => 'integer',
            'retire_duration' => 'integer',
            'retire_monthly' => 'decimal:2',
            'retire_index_contributions' => 'boolean',
            'retire_inflation' => 'decimal:2',
            'retire_fees' => 'decimal:2',
            'retire_haircut' => 'decimal:2',
            'retire_withdrawal' => 'decimal:2',
            'retire_target_income' => 'decimal:2',
            'retire_spending' => 'decimal:2',
            'reserve_profile' => 'array',
        ];
    }
}
