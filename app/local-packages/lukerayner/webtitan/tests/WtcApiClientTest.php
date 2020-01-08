<?php 
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use WtcApiClient\WtcApiClient;

$wtc_url = "https://35.176.129.240:8443/";
$consumer_key = "e22ed431cd60dda4359793dd2ed679f4";     //Enter your consumer_key here
$consumer_secret = "64d1718bdb5d14ce5380b7c6d7f8947c";      //Enter your consumer_secret here
$oauth_token = "73f1219beb6529ec3d4b6e2311db66c8";      //Enter your oauth_token here
$oauth_token_secret = "4331810bb0f32bc787ef2d55e110065f";   //Enter your oauth_token_secret here

$WtcApiClient = new WtcApiClient();
$WtcApiClient->setOAuthCredentials($wtc_url, $consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
// $response = $WtcApiClient->listCustomerAccounts();
$response = $WtcApiClient->getTop10SiteDomains(3, 'blocked', '2018-04-26');
echo $response;