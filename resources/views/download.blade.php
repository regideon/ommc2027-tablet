<!DOCTYPE html>
<html>
<head>
<title>Download Site</title>
<meta name="robots" content="noindex,nofollow">
<style>
body {
    background-color: #FFF;
}
.wrapper {
    text-align: center;
}

.dload-link {
    display: table;
    margin: 50px auto 0;
    text-decoration: none;
    color: black;
    font-weight: bold;
}
a {
    font-weight: 100;
    font-decoration: none;
}
.dload_img {
    -webkit-box-shadow: 1px 2px 2px 2px #ccc;
    -moz-box-shadow: 1px 2px 2px 2px #ccc;
    box-shadow: 1px 2px 2px 2px #ccc;
    border-radius: 30px;
}
.history {
    text-align: left;
}
</style>

</head>
<body>

<div class="wrapper">
    <a href="itms-services://?action=download-manifest&url={{ $data['download_link'] }}" class="dload-link" id="text">
        <img src="{{ $data['logo'] }}" width="40%" style="border: 2px solid #0033FF;" alt="{{ $data['title'] }} icon"><br />
        {{ $data['title'] }} (iOS)
    </a>
    <a href="{{ $data['apk_link'] }}" class="dload-link">
        <img src="{{ $data['logo'] }}" width="40%" style="border: 2px solid #0033FF;" alt="{{ $data['title'] }} icon"><br />
        {{ $data['title'] }} (Android)
    </a>
</div>

</body>
</html>
