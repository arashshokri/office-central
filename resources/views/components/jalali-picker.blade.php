@props(['name', 'value' => '', 'label', 'id' => 'licenseExpiry'])
<div class="date-field" data-jalali-picker data-date-empty="{{ __('ui.choose_jalali_date') }}">
    <label for="{{ $id }}">{{ $label }}</label>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-date-value>
    <button id="{{ $id }}" class="date-trigger" type="button" aria-haspopup="dialog" aria-expanded="false" data-date-open>
        <span data-date-text>{{ $value ? \App\Support\PanelDate::format($value, 'Y/m/d') : __('ui.choose_jalali_date') }}</span><x-icon name="calendar"/>
    </button>
    <div class="jalali-calendar" role="dialog" aria-label="{{ __('ui.choose_jalali_date') }}" hidden data-date-popup>
        <div class="calendar-heading"><button type="button" data-date-prev aria-label="{{ __('ui.previous_month') }}">‹</button><div><select data-date-month aria-label="{{ __('ui.month') }}"></select><select data-date-year aria-label="{{ __('ui.year') }}"></select></div><button type="button" data-date-next aria-label="{{ __('ui.next_month') }}">›</button></div>
        <div class="calendar-weekdays" aria-hidden="true">@foreach(['ش','ی','د','س','چ','پ','ج'] as $day)<span>{{ $day }}</span>@endforeach</div>
        <div class="calendar-days" data-date-days></div>
        <div class="calendar-actions"><button type="button" data-date-today>{{ __('ui.today') }}</button><button type="button" data-date-clear>{{ __('ui.clear_expiry') }}</button><button type="button" data-date-close>{{ __('ui.close') }}</button></div>
    </div>
</div>
