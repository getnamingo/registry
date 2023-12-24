<?php
session_start();
use Gregwar\Captcha\CaptchaBuilder;

require 'vendor/autoload.php';

$captcha = new CaptchaBuilder;
$captcha->build();

$_SESSION['captcha'] = $captcha->getPhrase();

header('Content-type: image/jpeg');
$captcha->output();