<html>
<head>
    <title>Test search page</title>
</head>
<body>
    <p>This page is used for test search responses</p>
    @if (isset($products))
        <ul>
            @foreach ($products as $product)
                <li><h3><a href="{{ data_get($product, 'url') }}">{{ data_get($product, 'title') }}</a></h3></li>
            @endforeach
        </ul>
    @endif
</body>
</html>
