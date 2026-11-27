<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;
use Laravel\Sanctum\HasApiTokens;
use App\Models\ChatBot\ChatMessage;


class User extends Authenticatable
{
    public function sendPasswordResetNotification($token)
{
    $this->notify(new ResetPasswordNotification($token));
}
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory,HasApiTokens, Notifiable;

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

     protected $casts = [
        'email_verified_at' => 'datetime',
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
            // is_admin, role et actif sont volontairement absents de $fillable :
            // ils ne s'attribuent que par definirRole() / l'écran des utilisateurs.
            'is_admin' => 'boolean',
            'actif' => 'boolean',
            'derniere_connexion' => 'datetime',
        ];
    }

    // ---- Rôles -----------------------------------------------------------

    public const ROLE_ADMIN = 'admin';
    public const ROLE_COMPTABLE = 'comptable';
    public const ROLE_LECTEUR = 'lecteur';

    /** Rôles et leur description (écran Paramètres › Utilisateurs). */
    public const ROLES = [
        self::ROLE_ADMIN => 'Administrateur : tout, y compris paramètres, utilisateurs et annulation de clôture',
        self::ROLE_COMPTABLE => 'Comptable : saisie, validation, plan comptable, clôture, états',
        self::ROLE_LECTEUR => 'Lecteur : consultation seule (états, grand livre, tableau de bord)',
    ];

    public function estAdmin(): bool
    {
        return ($this->role ?? null) === self::ROLE_ADMIN || (bool) $this->is_admin;
    }

    public function peutModifier(): bool
    {
        return ($this->role ?? self::ROLE_COMPTABLE) !== self::ROLE_LECTEUR;
    }

    /** Change le rôle en gardant is_admin synchronisé (non sauvegardé). */
    public function definirRole(string $role): static
    {
        $this->forceFill(['role' => $role, 'is_admin' => $role === self::ROLE_ADMIN]);
        return $this;
    }

     /**
     * Relation avec les messages du chatbot
     */
    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }


}
