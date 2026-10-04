<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp_payment_opt_in_at',
        'whatsapp_payment_opt_in_phone',
        'whatsapp_payment_opt_in_source',
        'password',
        'mac_address',
        'ip_address',
        'device_name',
        'last_mikrotik_id',
        'connected_at',
        'expires_at',
        'last_login_at',
        'last_login_ip',
        'data_used',
        'status',
        'role',
        'allowed_modules',
        'registered_at',
        'email_verified_at',
        'voucher_id',
        'driver_phone',
        'voucher_activated_at',
        'voucher_last_connection',
        'voucher_daily_minutes_used',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'connected_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'registered_at' => 'datetime',
            'whatsapp_payment_opt_in_at' => 'datetime',
            'voucher_activated_at' => 'datetime',
            'voucher_last_connection' => 'datetime',
            'allowed_modules' => 'array',
        ];
    }

    /**
     * Relacionamentos
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function sessions()
    {
        return $this->hasMany(Session::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * Verifica se o usuário está conectado
     */
    public function isConnected(): bool
    {
        return $this->status === 'connected' && $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Consentimento explícito para atualizações transacionais deste telefone.
     * O número faz parte do consentimento para evitar reutilizar uma aceitação
     * antiga caso o passageiro informe outro telefone no portal.
     */
    public function hasWhatsappPaymentOptIn(): bool
    {
        $phone = preg_replace('/\D/', '', (string) $this->phone);
        $consentedPhone = preg_replace('/\D/', '', (string) $this->whatsapp_payment_opt_in_phone);

        return (bool) $this->whatsapp_payment_opt_in_at
            && $phone !== ''
            && $phone === $consentedPhone;
    }

    /**
     * Verifica se é um motorista com voucher
     */
    public function isDriver(): bool
    {
        return !empty($this->voucher_id) && !empty($this->driver_phone);
    }

    /**
     * Verifica se tem voucher ativo
     */
    public function hasActiveVoucher(): bool
    {
        return $this->isDriver() 
            && $this->voucher 
            && $this->voucher->isValid() 
            && $this->isConnected();
    }

    /**
     * Modulos disponiveis para gerentes
     */
    public const AVAILABLE_MODULES = [
        'dashboard' => 'Dashboard',
        'reports' => 'Relatorios',
        'vouchers' => 'Vouchers',
        'chat' => 'Chat',
        'reviews' => 'Avaliacoes',
        'tourism' => 'Turismo',
    ];

    /**
     * Verifica se o usuario tem acesso a um modulo
     */
    public function hasModule(string $module): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        if ($this->role !== 'manager') {
            return false;
        }

        $modules = $this->allowed_modules ?? [];

        return in_array($module, $modules);
    }

    /**
     * Retorna a rota do primeiro modulo disponivel para o usuario
     */
    public function getHomeRoute(): string
    {
        if ($this->role === 'admin') {
            return route('admin.dashboard');
        }

        $moduleRoutes = [
            'dashboard' => 'admin.dashboard',
            'reports' => 'admin.reports',
            'vouchers' => 'admin.vouchers.index',
            'chat' => 'admin.chat.index',
            'reviews' => 'admin.reviews.index',
            'tourism' => 'admin.tourism.index',
        ];

        foreach ($this->allowed_modules ?? [] as $module) {
            if (isset($moduleRoutes[$module])) {
                return route($moduleRoutes[$module]);
            }
        }

        return route('admin.dashboard');
    }
}
