<?php
require 'vendor/autoload.php';

use Gregwar\Captcha\CaptchaBuilder;

$builder = new CaptchaBuilder();
$builder->build();
session_start();
$_SESSION['captcha'] = $builder->getPhrase();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Whois Check</title>
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic">
    <link rel="stylesheet" href="//cdn.rawgit.com/milligram/milligram/master/dist/milligram.min.css">
    <script src="script.js"></script>
    <style>
        .row {
            display: flex;
            align-items: center;
        }
        .domain-input {
            width: 45%;
        }
        .captcha-input {
            width: 35%;
			margin-left:10px;
        }
        .captcha-container {
            display: flex;
            align-items: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-center">Whois Check</h1>
        <form id="whoisForm">
            <div class="row">
                <div class="domain-input">
                    <label for="domain">Domain</label>
                    <input type="text" id="domain" name="domain" placeholder="example.com" required>
                </div>
                <div class="captcha-input">
                    <label for="captcha">Captcha</label>
                    <div class="captcha-container">
                        <input type="text" id="captcha" name="captcha" required>
                        <img src="<?php echo $builder->inline(); ?>" id="captchaImage" style="margin-left:10px;">
                    </div>
                </div>
            </div>
            <button type="submit" class="button-primary">Check Whois</button>
        </form>
        <div id="result" class="mt-4"></div>
    </div>

</body>
</html>