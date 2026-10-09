<section class="feature-plan wide-field" data-feature-plan>
    <div class="section-heading"><div><h2>{{ __('ui.planned_features') }}</h2><p>{{ __('ui.feature_planning_notice') }}</p></div><span class="badge draft">{{ __('ui.planning_only') }}</span></div>
    <label>{{ __('ui.feature_selection_mode') }}<select name="planned_feature_policy" data-feature-policy><option value="all" @selected(old('planned_feature_policy',$license?->planned_feature_policy ?? 'all') === 'all')>{{ __('ui.all_product_features') }}</option><option value="selected" @selected(old('planned_feature_policy',$license?->planned_feature_policy) === 'selected')>{{ __('ui.selected_product_features') }}</option></select></label>
    <div class="feature-choices" data-feature-choices>
    @forelse($features as $feature)
        <label class="feature-choice" data-feature-product="{{ $feature->product_id }}">
            @if($feature->is_required)<input type="checkbox" checked disabled><span><strong>{{ $feature->name }}</strong><small>{{ __('ui.required_feature') }}</small></span>
            @else<input type="checkbox" name="features[]" value="{{ $feature->id }}" @checked(in_array((string)$feature->id,array_map('strval',old('features',$license?->features->pluck('id')->all() ?? [])),true))><span><strong>{{ $feature->name }}</strong><small>{{ $feature->category ?: __('ui.optional_feature') }}@unless($feature->active) · {{ __('ui.state_inactive') }}@endunless</small></span>@endif
        </label>
    @empty<p class="muted">{{ __('ui.no_features') }} <a href="{{ route('features.create') }}">{{ __('ui.new_feature') }}</a></p>@endforelse
    </div>
    @error('features')<small class="field-error">{{ $message }}</small>@enderror
    @error('features.*')<small class="field-error">{{ $message }}</small>@enderror
</section>
