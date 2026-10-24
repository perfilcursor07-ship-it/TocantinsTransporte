<?php

namespace App\Models;

use App\Helpers\SettingsHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DiscountSponsor extends Model
{
    use HasFactory;

    public const TYPE_VIDEO = 'video';
    public const TYPE_IFRAME = 'iframe';
    public const TYPE_API = 'api';

    public const TYPES = [self::TYPE_VIDEO, self::TYPE_IFRAME, self::TYPE_API];

    protected $fillable = [
        'name', 'slug', 'type', 'is_active',
        'headline', 'subheadline',
        'discount_amount', 'min_seconds', 'collects_cpf',
        'config', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'collects_cpf' => 'boolean',
        'config' => 'array',
        'discount_amount' => 'decimal:2',
    ];

    /** Patrocinador que ocupa o espaço agora (só um por vez). */
    public static function active(): ?self
    {
        return Cache::remember('discount_sponsor_active', 300, function () {
            return static::where('is_active', true)->orderByDesc('id')->first();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget('discount_sponsor_active');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }

    /** Valor do desconto: o do patrocinador, ou o global. */
    public function discountAmount(): float
    {
        return (float) ($this->discount_amount ?: SettingsHelper::getVideoDiscountAmount());
    }

    /** Tempo mínimo de exposição: o do patrocinador, ou o global. */
    public function minSeconds(): int
    {
        return (int) ($this->min_seconds ?: SettingsHelper::getCashCashMinSeconds());
    }

    public function cfg(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    public function leads()
    {
        return $this->hasMany(DiscountLead::class, 'sponsor_id');
    }
}
