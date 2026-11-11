{{-- Mantém o mês de ganhos e os filtros ao voltar de uma ação --}}
<input type="hidden" name="tab" value="{{ $contextTab ?? request('tab', 'drivers') }}">
<input type="hidden" name="ganhos" value="{{ $ganhosMes ?? request('ganhos', '') }}">
@if(request('bus'))<input type="hidden" name="bus" value="{{ request('bus') }}">@endif
@if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
@if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
