<?php

require_once '../vendor/autoload.php';


/**
 * Load configuration
 */

$dotenv = new Dotenv\Dotenv(realpath(__DIR__ . '/..'));
$dotenv->load();
$dotenv->required([
    'BASECAMP_OAUTH_CLIENTID', 'BASECAMP_OAUTH_CLIENTSECRET',
    'BASECAMP_APP_URI_AUTH_REDIRECT',
    'BASECAMP_APP_NAME', 'BASECAMP_APP_CONTACT',
]);

date_default_timezone_set('Europe/Vienna');

session_name('basecamptest');
session_start();


/**
 * Fabrik
 */
class Fabrik
{
    /**
     * Get Basecamp OAuth provider based on ENV options
     *
     * @return \Uberboom\OAuth2\Client\Provider\Basecamp
     */
    public static function getBasecampProvider()
    {
        $basecampOptions = [
            'clientId'      => $_ENV['BASECAMP_OAUTH_CLIENTID'],
            'clientSecret'  => $_ENV['BASECAMP_OAUTH_CLIENTSECRET'],
            'redirectUri'   => $_ENV['BASECAMP_APP_URI_AUTH_REDIRECT'],
            'scopes'        => ['email'],
        ];
        return new Uberboom\OAuth2\Client\Provider\Basecamp($basecampOptions);
    }

    /**
     * Get Basecamp client based on ENV options
     *
     * @return \Basecamp\BasecampClient
     */
    public static function getBasecampClient()
    {
        if (empty($_SESSION['oauth_token']->accessToken)) {
            throw new Exception('OAuth access token is not set.');
        }
        if (empty($_SESSION['basecamp']['account']->id)) {
            throw new Exception('Basecamp Account Id is not set.');
        }
        return \Basecamp\BasecampClient::factory(array(
            'auth'     => 'oauth',
            'token'    => $_SESSION['oauth_token']->accessToken,
            // 'user_id'   => $userDetails->uid,
            'user_id'   => $_SESSION['basecamp']['account']->id,
            'app_name' => $_ENV['BASECAMP_APP_NAME'],
            'app_contact' => $_ENV['BASECAMP_APP_CONTACT'],
        ));
    }

}


/**
 * Setup slim
 */

// Create Slim app
$app = new \Slim\App();

// Fetch DI Container
$container = $app->getContainer();

// Register Twig View helper
$twigView = new \Slim\Views\Twig('../resources/views', [
    // 'cache' => '../storage/cache/views'
    'debug' => true,
]);
$twigView->addExtension(new Twig_Extension_Debug());
$container->register($twigView);


/**
 * Add Base URL and Base Path variables to all views
 */
$app->add(function ($request, $response, $next) {
    $this->get('view')->offsetSet('basePath', $request->getUri()->getBasePath());
    $this->get('view')->offsetSet('baseUrl', $request->getUri()->getBaseUrl());
    return $next($request, $response);
});


/**
 * Home
 */
$app->get('/', function ($request, $response, $args) {

    $provider = Fabrik::getBasecampProvider();

    $userDetails = null;
    if (!empty($_SESSION['oauth_token']) && empty($_SESSION['basecamp']['user'])) {
        $userDetails = null;
        // We got an access token, let's now get the user's details
        try {
            $userDetails = $provider->getUserDetails($_SESSION['oauth_token']);
        } catch (Exception $e) {
            // todo
        }
        $_SESSION['basecamp']['user'] = $userDetails;
    }

    // get basecamp accounts
    if (!empty($_SESSION['oauth_token']) && empty($_SESSION['basecamp']['accounts'])) {

        $basecampAccounts = $provider->getBasecampAccounts($_SESSION['oauth_token']);
        $_SESSION['basecamp']['accounts'] = [];
        foreach ($basecampAccounts as $account) {
            $_SESSION['basecamp']['accounts'][$account->id] = $account;
        }

        // set account to first item
        $_SESSION['basecamp']['account'] = array_slice($_SESSION['basecamp']['accounts'], 0, 1)[0];

    }

    // Use these details to create a new profile
    // printf('<h3>Hello %s %s!</h3>', $userDetails->firstName, $userDetails->lastName);

    return $this->view->render($response, 'index.html', [
        'userDetails' => !empty($_SESSION['basecamp']['user']) ? $_SESSION['basecamp']['user'] : null,
        'basecampAccounts' => !empty($_SESSION['basecamp']['accounts']) ? $_SESSION['basecamp']['accounts'] : null,
    ]);

});


/**
 * Debug
 */
$app->get('/debug/', function ($request, $response, $args) {

    $basecampClient = Fabrik::getBasecampClient();
    $projects = $basecampClient->getProjects();

    $todoLists = [];
    foreach ($projects as $project) {
        $todoLists[$project['id']] = $basecampClient->getTodolistsByProject([ 'projectId' => $project['id'] ]);
    }

    return $this->view->render($response, 'debug.html', [
        'userDetails' => $_SESSION['basecamp']['user'],
        'basecampAccounts' => $_SESSION['basecamp']['accounts'],
        'projects' => $projects,
        'todolists' => $todoLists,
    ]);

})->setName('debug');

/**
 * Login
 */
$app->get('/login/', function ($request, $response, $args) {
    if (empty($_SESSION['oauth_token'])) {
        return $response->withRedirect($request->getUri()->getBasePath() . '/auth/');
    } else {
        return $response->withRedirect($request->getUri()->getBasePath() . '/');
    }
})->setName('login');


/**
 * Logout
 */
$app->get('/logout/', function ($request, $response, $args) {
    foreach ($_SESSION as $key => $value) {
        unset($_SESSION[$key]);
    }
    session_destroy();
    return $response->withRedirect($request->getUri()->getBasePath() . '/');
})->setName('logout');


/**
 * Switch account
 */
$app->get('/account/switch/{id}/', function ($request, $response, $args) {
    if (!empty($_SESSION['basecamp']['accounts'][$args['id']])) {
        $_SESSION['basecamp']['account'] = $_SESSION['basecamp']['accounts'][$args['id']];
    }
    return $response->withRedirect($request->getUri()->getBasePath() . '/');
})->setName('account/switch');


/**
 * Authenticate
 */
$app->get('/auth/', function ($request, $response, $args) {

    $provider = Fabrik::getBasecampProvider();

    if (!isset($_GET['code'])) {

    	$_SESSION['oauth_called'] = true;

        // If we don't have an authorization code then get one
        $authUrl = $provider->getAuthorizationUrl();
        $_SESSION['oauth2state'] = $provider->state;
        return $response->withRedirect($authUrl);

    // Check given state against previously stored one to mitigate CSRF attack
    } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {

        unset($_SESSION['oauth2state']);
        exit('Invalid state');

    } else {

        // Try to get an access token (using the authorization code grant)
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code'],
        ]);

        $_SESSION['oauth_token'] = $token;

        return $response->withRedirect($request->getUri()->getBasePath() . '/');

    }

});


/**
 * API
 */

$app->get('/api/basecamp/projects/', function ($request, $response, $args) {
    $basecampClient = Fabrik::getBasecampClient();
    $result = $basecampClient->getProjects();
    return $response->withJson($result);
})->setName('api/basecamp/projects');

$app->get('/api/basecamp/people/', function ($request, $response, $args) {
    $basecampClient = Fabrik::getBasecampClient();
    $result = $basecampClient->getPeople();
    return $response->withJson($result);
})->setName('api/basecamp/people');

$app->get('/api/basecamp/projects/{projectId}/todolists/', function ($request, $response, $args) {
    $basecampClient = Fabrik::getBasecampClient();
    $result = $basecampClient->getTodolistsByProject([ 'projectId' => (int) $args['projectId'] ]);
    return $response->withJson($result);
})->setName('api/basecamp/projects/id/todolists');

$app->get('/api/basecamp/projects/{projectId}/todolists/{todolistId}/', function ($request, $response, $args) {
    $basecampClient = Fabrik::getBasecampClient();
    $result = $basecampClient->getTodolist([ 'projectId' => (int) $args['projectId'], 'todolistId' => (int) $args['todolistId'] ]);
    return $response->withJson($result);
})->setName('api/basecamp/projects/id/todolists/id');


/**
 * Deliver
 */
$app->run();

exit;






/**
 * Debug output
 */
//
// echo '<h3>Debug</h3>';
//
// // Use this to interact with an API on the users behalf
// echo "<p>accessToken: " . $_SESSION['oauth_token']->accessToken . "</p>";
//
// // Use this to get a new access token if the old one expires
// echo "<p>refreshToken: " . $_SESSION['oauth_token']->refreshToken . "</p>";
//
// // Unix timestamp of when the token will expire, and need refreshing
// echo "<p>expires: " . $_SESSION['oauth_token']->expires . " (" . date('Y-m-d H:i', $_SESSION['oauth_token']->expires) . ")</p>";
//
// echo '<h4>Session</h4>';
// var_dump($_SESSION);

?>
