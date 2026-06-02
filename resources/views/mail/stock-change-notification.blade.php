<x-mail::message>

<table class="product-card" width="100%" cellpadding="0" cellspacing="20" role="presentation">
@if($imgUrl)
<tr>
<td align="center" style="max-height: 200px" class="product-image">
<a href="{{ $productUrl }}"><img src="{{ $imgUrl }}" /></a>
</td>
</tr>
@endif
<tr>
<td align="center">
<p>
<strong>{{ $storeName }}</strong>
{{ __('has this product back in stock') }}
<br />
<a href="{{ $productUrl }}">{{ $productName }}</a>.
</p>
</td>
</tr>
</table>

<div class="product-actions">
<x-mail::button :url="$buyUrl" color="primary">
{{ $buyText }}
</x-mail::button>
</div>

</x-mail::message>
