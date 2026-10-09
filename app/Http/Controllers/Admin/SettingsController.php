<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function index()
    {
        $legacyIntervalPrice12h = (float) SystemSetting::getValue('plan_interval_price_12h', '6.99');
        $settings = [
            'plan_interval_enabled' => SystemSetting::getValue('plan_interval_enabled', '0'),
            'plan_interval_max_days' => SystemSetting::getValue('plan_interval_max_days', '30'),
            'plan_interval_price_24h' => SystemSetting::getValue('plan_interval_price_24h', (string) ($legacyIntervalPrice12h * 2)),
            'wifi_price' => SystemSetting::getValue('wifi_price', '5.99'),
            'wifi_price_full' => SystemSetting::getValue('wifi_price_full', '6.99'),
            'pix_gateway' => SystemSetting::getValue('pix_gateway', 'pagbank'),
            'session_duration' => SystemSetting::getValue('session_duration', '12'),
            'session_duration_short' => SystemSetting::getValue('session_duration_short', '1'),
            'plan_short_enabled' => SystemSetting::getValue('plan_short_enabled', '1'),
            'plan_full_enabled' => SystemSetting::getValue('plan_full_enabled', '1'),
            'plan_short_schedule_enabled' => SystemSetting::getValue('plan_short_schedule_enabled', '0'),
            'plan_short_schedule_start' => SystemSetting::getValue('plan_short_schedule_start', '21:00'),
            'plan_short_schedule_end' => SystemSetting::getValue('plan_short_schedule_end', '06:00'),
            'pagbank_account' => SystemSetting::getValue('pagbank_account', 'junior'),
            'pagbank_email' => SystemSetting::getValue('pagbank_email', 'juniormoreiragloboplay@gmail.com'),
            'pagbank_token' => SystemSetting::getValue('pagbank_token', 'c75a2308-ec9d-4825-94fd-bacba8a7248344f58a634d1b857348dba39f6a5b6c957b2a-2890-4da4-9866-af24b6eee984'),
            'video_discount_enabled' => SystemSetting::getValue('video_discount_enabled', '1'),
            'video_discount_amount' => SystemSetting::getValue('video_discount_amount', '1.00'),
            'plan_short_currently_active' => \App\Helpers\SettingsHelper::isPlanShortCurrentlyActive(),
            'unpaid_reminder_enabled' => SystemSetting::getValue('unpaid_reminder_enabled', '1'),
            'promo_timer_enabled' => SystemSetting::getValue('promo_timer_enabled', '0'),
            'promo_timer_minutes' => SystemSetting::getValue('promo_timer_minutes', '5'),
            'promo_timer_after_price' => SystemSetting::getValue('promo_timer_after_price', '8.99'),
        ];

        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'plan_interval_enabled' => 'nullable|in:0,1',
            'plan_interval_max_days' => 'required|integer|min:1|max:90',
            'plan_interval_price_24h' => 'required|numeric|min:0.05|max:999.99|decimal:0,2',
            'wifi_price' => 'required|numeric|min:0.01|max:999.99',
            'wifi_price_full' => 'required|numeric|min:0.01|max:999.99',
            'pix_gateway' => 'required|in:woovi,pagbank,santander',
            'session_duration' => 'required|integer|min:1|max:168',
            'session_duration_short' => 'required|integer|min:1|max:168',
            'plan_short_enabled' => 'nullable|in:0,1',
            'plan_full_enabled' => 'nullable|in:0,1',
            'plan_short_schedule_enabled' => 'nullable|in:0,1',
            'plan_short_schedule_start' => 'nullable|date_format:H:i',
            'plan_short_schedule_end' => 'nullable|date_format:H:i',
            'pagbank_account' => 'nullable|in:junior,erick',
            'pagbank_email' => 'nullable|email|max:255',
            'pagbank_token' => 'nullable|string|max:500',
            'video_discount_enabled' => 'nullable|in:0,1',
            'video_discount_amount' => 'required|numeric|min:0.01|max:99.99',
            'unpaid_reminder_enabled' => 'nullable|in:0,1',
            // Estes três ainda não existem no formulário — se forem obrigatórios,
            // toda gravação da tela falha calada e nada é salvo.
            'promo_timer_enabled' => 'nullable|in:0,1',
            'promo_timer_minutes' => 'nullable|integer|min:1|max:60',
            'promo_timer_after_price' => 'nullable|numeric|max:999.99|gt:wifi_price_full',
        ], [
            'promo_timer_after_price.gt' => 'O preço depois do cronômetro precisa ser maior que o preço da promoção (Viagem completa).',
        ]);

        SystemSetting::setValue('wifi_price', $request->wifi_price);
        SystemSetting::setValue('plan_interval_enabled', $request->input('plan_interval_enabled', '0'));
        SystemSetting::setValue('plan_interval_max_days', $request->plan_interval_max_days);
        SystemSetting::setValue('plan_interval_price_24h', $request->plan_interval_price_24h);
        SystemSetting::setValue('wifi_price_full', $request->wifi_price_full);
        SystemSetting::setValue('pix_gateway', $request->pix_gateway);
        SystemSetting::setValue('session_duration', $request->session_duration);
        SystemSetting::setValue('session_duration_short', $request->session_duration_short);
        SystemSetting::setValue('plan_short_enabled', $request->input('plan_short_enabled', '0'));
        SystemSetting::setValue('plan_full_enabled', $request->input('plan_full_enabled', '0'));
        SystemSetting::setValue('plan_short_schedule_enabled', $request->input('plan_short_schedule_enabled', '0'));
        SystemSetting::setValue('plan_short_schedule_start', $request->input('plan_short_schedule_start', '21:00'));
        SystemSetting::setValue('plan_short_schedule_end', $request->input('plan_short_schedule_end', '06:00'));
        SystemSetting::setValue('video_discount_enabled', $request->input('video_discount_enabled', '0'));
        SystemSetting::setValue('video_discount_amount', $request->video_discount_amount);
        SystemSetting::setValue('unpaid_reminder_enabled', $request->input('unpaid_reminder_enabled', '0'));

        // Só grava o cronômetro de promoção se o formulário realmente mandar o campo.
        // Sem isso, uma tela que não tem esses campos apagaria o que está salvo.
        foreach (['promo_timer_enabled', 'promo_timer_minutes', 'promo_timer_after_price'] as $key) {
            if ($request->has($key)) {
                SystemSetting::setValue($key, $request->input($key));
            }
        }


        // Salvar conta e credenciais PagBank
        if ($request->filled('pagbank_account')) {
            SystemSetting::setValue('pagbank_account', $request->pagbank_account);
        }
        if ($request->filled('pagbank_email')) {
            SystemSetting::setValue('pagbank_email', $request->pagbank_email);
        }
        if ($request->filled('pagbank_token')) {
            SystemSetting::setValue('pagbank_token', $request->pagbank_token);
        }

        // Limpar cache de configurações (aplica imediatamente sem precisar de artisan)
        \App\Helpers\SettingsHelper::clearCache();
        
        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Configurações atualizadas com sucesso!');
    }
}
