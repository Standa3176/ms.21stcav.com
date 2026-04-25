<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

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
     * Phase 9 B-02 — customer_group_id is intentionally NOT in $fillable.
     * Listener + backfill use forceFill(). This eliminates the mass-assignment
     * vector via Breeze ProfileController + RegisteredUserController + future
     * API forms. The cast is still required so reads return int (DB drivers
     * can return strings for BIGINT columns).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'customer_group_id' => 'integer',
        ];
    }

    /**
     * Phase 9 Plan 04 — BelongsTo CustomerGroup (D-08 denormalised FK).
     *
     * Resolves the user's B2B customer group membership. null = retail
     * (default for any user not synced from a Woo trade role per D-07).
     * Used by Phase 11 quote flow and any order-time price resolver to
     * call TradeRuleResolver::resolve($product, $user->customer_group_id).
     */
    public function customerGroup(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\TradePricing\Models\CustomerGroup::class);
    }

    /**
     * Filament panel access guard.
     *
     * Phase 1 policy: any authenticated user may enter /admin; Resource-level
     * permissions are enforced by Shield-generated policies (D-01, D-02).
     * Tightened in later phases if needed.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
