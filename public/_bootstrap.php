<?php

$basecampOptions = [
    'clientId'      => 'af8fc0e8f902a4197e249984ceb098484e269405',
    'clientSecret'  => '6269bb6953e1d43e80bf7665b27be0f6f92bf112',
    'redirectUri'   => 'http://localhost/test/basecamp/public/auth.php',
    // 'scopes'        => ['public_profile', 'email'],
    'scopes'        => ['email'],
];
$basecampAccountId = 2979322;
$basecampApp = [
    'name' => 'Resource Planning 2',
    'contact' => 'basecamp@pizzinini.net'
];

$provider = new Uberboom\OAuth2\Client\Provider\Basecamp($basecampOptions);

