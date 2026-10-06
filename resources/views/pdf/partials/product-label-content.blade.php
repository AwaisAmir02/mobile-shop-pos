<div class="shop">{{ $label['shopName'] }}</div>
<div class="name">{{ $label['name'] }}</div>
<img class="barcode-img" src="{{ $label['barcodeImage'] }}" alt="">
<div class="number">{{ $label['barcode'] }}</div>
@if ($label['price'])
    <div class="price">{{ $label['price'] }}</div>
@endif
