<details class="search-panel" @if(collect(request()->only(['q','status','product_id','channel','kind','category']))->filter()->isNotEmpty()) open @endif>
    <summary><span><x-icon name="list"/> {{ __('ui.advanced_search') }}</span><span>{{ __('ui.filter_records') }}</span></summary>
    <form method="get" class="search-form">
        <label>{{ __('ui.search') }}<input type="search" name="q" value="{{ request('q') }}" maxlength="{{ $searchScope === 'features' ? 120 : 255 }}" placeholder="{{ __('ui.search_'.$searchScope) }}"></label>
        @isset($products)<label>{{ __('ui.product') }}<select name="product_id"><option value="">{{ __('ui.all_products') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)request('product_id') === (string)$product->id)>{{ $product->name }}</option>@endforeach</select></label>@endisset
        <label>{{ __('ui.status') }}<select name="status"><option value="">{{ __('ui.all_statuses') }}</option>@foreach(match($searchScope){'licenses'=>['created','active','suspended','expired','revoked'],'releases'=>['draft','published'],default=>['active','inactive']} as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ __('ui.state_'.$status) }}</option>@endforeach</select></label>
        @if($searchScope === 'releases')
        <label>{{ __('ui.channel') }}<select name="channel"><option value="">{{ __('ui.all_channels') }}</option>@foreach(['stable','beta','alpha','internal'] as $channel)<option value="{{ $channel }}" @selected(request('channel') === $channel)>{{ __('ui.channel_'.$channel) }}</option>@endforeach</select></label>
        <label>{{ __('ui.package_type') }}<select name="kind"><option value="">{{ __('ui.all_types') }}</option>@foreach(['runtime'=>'protected_runtime','source'=>'source_archive','security'=>'security_update'] as $kind=>$label)<option value="{{ $kind }}" @selected(request('kind') === $kind)>{{ __('ui.'.$label) }}</option>@endforeach</select></label>
        @endif
        @isset($categories)<label>{{ __('ui.category') }}<select name="category"><option value="">{{ __('ui.all_categories') }}</option>@foreach($categories as $category)<option @selected(request('category') === $category)>{{ $category }}</option>@endforeach</select></label>@endisset
        <div class="form-actions"><button class="primary">{{ __('ui.apply_filters') }}</button><a class="secondary button" href="{{ request()->url() }}">{{ __('ui.clear_filters') }}</a></div>
    </form>
</details>
