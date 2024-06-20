<?php

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Psr\Http\Message\UploadedFileInterface;

require __DIR__ . '/vendor/autoload.php';

// initiate app container
$container = new Container();
AppFactory::setContainer($container);

// Add Twig template engine
$container->set('view', function () {
    return Twig::create(__DIR__ . '/views', ['cache' => false]);
});

$container->set('session', function () {
    return new \SlimSession\Helper();
});

$container->set('users', __DIR__ . '/users/');

$app = AppFactory::create();

//Twig template rendering
$app->add(
    TwigMiddleware::createFromContainer($app)
);

// session
$app->add(
    new \Slim\Middleware\Session([
        'name' => 'hl_session',
        'autorefresh' => true,
        'lifetime' => '1 hour',
    ])
);

function checkSession($session)
{
    // if sessons keys are not set
    if ((!isset($session['hl_account'])) || (!isset($session['hl_sign']))) {
        header("Location: /");
        die();
    }

    $account = $session['hl_account'];
    $usersdir = __DIR__ . '/users/' . $account . "/";
    $passwdFile = $usersdir . 'passwd';

    // Check if passwd file exists
    if (!file_exists($passwdFile)) {
        header("Location: /");
        die();
    }

    $cred = unserialize(file_get_contents($passwdFile));

    // Check if Session Sign == stored Sign
    if ($session['hl_sign'] != $cred[$account]) {
        header("Location: /");
        die();
    }
}

// Index
$app->get('/', function ($request, $response) {
    return $this->get('view')->render($response, 'index.html');
})->setName('index');

// Login to panel
$app->post('/login', function ($request, $response) {
    $data = $request->getParsedBody();

    if (isset($data['username']) && isset($data['passwd'])) {
        $session = $this->get('session');
        $account = trim(strip_tags($data['username']));
        $usersdir = $this->get('users') . $account . "/";
        $passwdFile = $usersdir . 'passwd';

        // New account
        if (!file_exists($usersdir)) {
            mkdir($usersdir, 0755);
            $cred = array($data['username'] => $data['passwd']);
            file_put_contents($passwdFile, serialize($cred));
        }

        $cred = unserialize(file_get_contents($passwdFile));
        if (isset($cred[$account])) {
            $passwd = $cred[$account];

            if ($passwd == $data['passwd']) {
                $session['hl_account'] = $account;
                $session['hl_sign'] = $passwd;
                $msg = "OK";
            } else {
                $msg = "Not OK";
            }
        } else {
            $msg = "Not OK";
        }
    } else {
        $msg = "Not OK";
    }

    $response->getBody()->write($msg);
    return$response;
})->setName('login');

// Log Out
$app->get('/logout', function ($request, $response) {
    $session = $this->get('session');

    $session->delete('hl_account');
    $session->delete('hl_sign');
    $session::destroy();

    return $response->withHeader('Location', '/')->withStatus(302);
})->setName('logout');

// User panel
$app->get('/panel', function ($request, $response) {
    $session = $this->get('session');
    checkSession($session);

    $account = $session['hl_account'];
    $fontsFile = __DIR__ . "/fonts.txt";
    $fonts = file($fontsFile, FILE_IGNORE_NEW_LINES);
    $userVars = $this->get('users') . $account . "/custom.json";

    if (file_exists($userVars)) {
        $vars = json_decode(file_get_contents($userVars), true);
        return $this->get('view')->render($response, 'panel.html', [
            'user' => $vars,
            'fonts' => $fonts,
            'account' => $account
        ]);
    } else {
        return $this->get('view')->render($response, 'panel.html', [
            'fonts' => $fonts,
            'account' => $account
        ]);
    }
})->setName('panel');

$app->post('/saveCustom', function ($request, $response, $args) {
    $session = $this->get('session');
    checkSession($session);

    $account = $session['hl_account'];

    $usersdir = $this->get('users') . $account . "/";
    $customFile = $usersdir . 'custom.json';

    $data = $request->getParsedBody();

    $newSettings = array(
        'font-family' => $data['data']['fontFamily'],
        'font-size' => (int)$data['data']['fontSize'],
        'text-color' => $data['data']['textColor'],
        'values-color' => $data['data']['valuesColor'],
        'hide-msg' => $data['data']['hideMsg'],
        'custom-anim' => $data['data']['customAnim'],
        'minimal-donation' => $data['data']['minimalDonation'],
        'redirect' => $data['data']['redirect'],
        'redirect-account' => $data['data']['redirectAccount']
    );

    $fields = json_encode($newSettings, JSON_PRETTY_PRINT);

    if (file_put_contents($customFile, $fields)) {
        $msg = "OK";
    } else {
        $msg = "Not OK";
    }

    $response->getBody()->write($msg);
    return$response;
})->setName('save-custom');

// Image save
$app->post('/saveImg', function ($request, $response, $args) {
    $session = $this->get('session');
    checkSession($session);

    $account = $session['hl_account'];

    $usersdir = $this->get('users') . $account . "/";
    $customFile = $usersdir . 'custom.json';
    if (!empty($_FILES)) {
        $tmpName = $_FILES['file']['tmp_name'];
        $targetFile = $usersdir . 'anim.gif';
        move_uploaded_file($tmpName, $targetFile);

        $msg = 'OK';

        $response->getBody()->write($msg);
        return$response;
    }
})->setName('save-img');

// Stream Page
$app->get('/stream/{account}', function ($request, $response, $args) {
    // take blacklist and make an array
    $accountsFile = __DIR__ . "/accounts.txt";
    $blacklist = file($accountsFile, FILE_IGNORE_NEW_LINES);

    $account = $args['account'];

    $userVars = __DIR__ . '/users/' . $account . "/custom.json";

    if (file_exists($userVars)) {
        $vars = json_decode(file_get_contents($userVars), true);
        return $this->get('view')->render($response, 'stream.html', [
            'user' => $vars,
            'account' => $account,
            'blacklist' => $blacklist
        ]);
    } else {
        //Render the stream page
        return $this->get('view')->render($response, 'stream.html', [
            'account' => $account,
            'blacklist' => $blacklist
        ]);
    }
})->setName('stream');

// Donation page
$app->get('/donate/{streamer}', function ($request, $response, $args) {
    $account = $args['streamer'];

    //remove @ if exists
    if (mb_substr($account, 0, 1) === "@") {
        $account = substr($account, 1);
    }

    // Load HiveEngine tokens list
    $tokens = file(__DIR__ . "/he-tokens.txt", FILE_IGNORE_NEW_LINES);

    $streamers = __DIR__ . "/users";
    $streamersList = glob($streamers . "/*", GLOB_ONLYDIR);
    $test = $streamers . "/" . $account;

    if (in_array($test, $streamersList)) {
        $userVars = __DIR__  . '/users/' . $account . "/custom.json";
        $vars = json_decode(file_get_contents($userVars), true);
        $limit = $vars['minimal-donation'];
    } else {
        $limit = "0.001";
    }

    if (($vars['redirect'] == true) && (isset($vars['redirect-account']))) {
        $redirectAccount = $vars['redirect-account'];
    } else $redirectAccount = null;

    // Render donation page
    return $this->get('view')->render($response, 'donate.html', [
        'streamer' => $account,
        'limit' => $limit,
        'tokens' => $tokens,
        'redirect' => $redirectAccount
    ]);
})->setName('donate');

// Donation page
$app->get('/list', function ($request, $response) {
    // Load blacklisted accounts list
    $accounts = file(__DIR__ . "/accounts.txt", FILE_IGNORE_NEW_LINES);

    // Render page
    return $this->get('view')->render($response, 'blacklist.html', [
        'accounts' => $accounts
    ]);
})->setName('list');

// Run app
$app->run();
