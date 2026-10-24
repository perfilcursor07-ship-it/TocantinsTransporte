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
        'name', 'legal_name', 'slug', 'type', 'is_active',
        'headline', 'subheadline',
        'discount_amount', 'min_seconds', 'collects_cpf', 'shares_phone', 'data_purpose',
        'config', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'collects_cpf' => 'boolean',
        'shares_phone' => 'boolean',
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

    /**
     * Dados do passageiro que saem para este parceiro, em linguagem de gente.
     * Alimenta o texto de autorização no portal — o passageiro precisa ver
     * exatamente o que está autorizando.
     */
    public function sharedData(): array
    {
        $dados = [];
        if ($this->collects_cpf) $dados[] = 'CPF';
        if ($this->shares_phone) $dados[] = 'telefone';
        return $dados;
    }

    public function sharesAnyData(): bool
    {
        return $this->collects_cpf || $this->shares_phone;
    }

    /** Nome usado no texto de autorização. */
    public function consentName(): string
    {
        return $this->legal_name ?: $this->name;
    }

    /** Frase de autorização mostrada ao passageiro, montada do cadastro. */
    public function consentText(): string
    {
        $dados = $this->sharedData();
        if (!$dados) {
            return '';
        }

        $lista = count($dados) === 1
            ? $dados[0]
            : implode(' e ', [implode(', ', array_slice($dados, 0, -1)), end($dados)]);

        $finalidade = $this->data_purpose ?: 'me apresentar uma oferta';

        return "Autorizo o WiFi Tocantins a enviar meu {$lista} para a {$this->consentName()}, "
            . "com a finalidade de {$finalidade}.";
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
