<html>
<head>
    @if (isset($title) && $title !== 'invalid')
        <meta property="og:title" content="{{ $title }}">
    @elseif (!isset($title))
        <meta property="og:title" content="Example product">
    @endif
    @if (isset($image) && $image !== 'invalid')
        <meta property="og:image" content="{{ $image }}">
    @elseif (!isset($image))
        <meta property="og:image" content="https://place-hold.it/300">
    @endif
    @if (isset($price) && $price !== 'invalid')
        <meta property="og:price:amount" content="{{ $price }}">
    @elseif (!isset($price))
        <meta property="og:price:amount" content="$15.00">
    @endif
</head>
<body>
    <p>This page is used for test responses</p>
</body>
</html>
