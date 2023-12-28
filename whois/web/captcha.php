<?php
session_start();
use Gregwar\Captcha\CaptchaBuilder;

require 'vendor/autoload.php';

$captcha = new CaptchaBuilder;
//$captcha->setBackgroundColor(255, 255, 255);
$captcha->setMaxAngle(25);
//$captcha->setMaxBehindLines(0);
//$captcha->setMaxFrontLines(0);
$captcha->setTextColor(0, 0, 0);
$captcha->setInterpolation(false);
$captcha->setDistortion(false);
$captcha->build($width = 100, $height = 40);

$_SESSION['captcha'] = $captcha->getPhrase();

header('Content-type: image/jpeg');
$captcha->output();