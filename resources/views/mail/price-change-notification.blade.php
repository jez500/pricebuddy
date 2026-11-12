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
{{ __('has had a price reduction for') }}
<br />
<a href="{{ $productUrl }}">{{ $productName }}</a>.
</p>
</td>
</tr>
</table>

<table class="product-price" width="100%" cellpadding="0" cellspacing="20" role="presentation">
<tr>
<td align="center">
<div class="text-biggest product-price-value">{{ $newPrice }}</div>
@if (! empty($priceBreakdown))
<p class="product-subtext">{{ $priceBreakdown }}</p>
@endif
</td>
</tr>
</table>

<p class="product-subtext">
{{ __('The average price for this product at :store is', ['store' => $storeName]) }} <strong>{{ $averagePrice }}</strong>
</p>

<div class="product-actions">
<x-mail::button :url="$buyUrl" color="primary">
{{ $buyText }}
</x-mail::button>
</div>

</x-mail::message>
