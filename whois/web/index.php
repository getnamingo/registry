<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WHOIS Lookup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/milligram/1.4.1/milligram.min.css">
</head>
<body>
    <div class="container">
        <h1>WHOIS Lookup</h1>
        <div class="row">
            <input type="text" id="domainInput" placeholder="Enter Domain Name" autocapitalize="none">
            <img id="captchaImg" src="captcha.php" onclick="this.src='captcha.php?'+Math.random();">
            <input type="text" id="captchaInput" placeholder="Enter Captcha" autocapitalize="none">
            <button id="whoisButton">WHOIS</button>
        </div>
        <div id="result"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('whoisButton').addEventListener('click', function() {
                var domain = document.getElementById('domainInput').value;
                var captcha = document.getElementById('captchaInput').value;

                fetch('whois.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'domain=' + encodeURIComponent(domain) + '&captcha=' + encodeURIComponent(captcha)
                })
                .then(response => response.text())
                .then(data => {
                    document.getElementById('result').innerText = data;
                    // Reload captcha after a successful response
                    document.getElementById('captchaImg').src = 'captcha.php?' + Math.random();
                })
                .catch(error => console.error('Error:', error));
            });
        });
    </script>

</body>
</html>