<?php


use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../vendor/autoload.php';

$app = new Silex\Application();

require_once __DIR__ . '/config.php';

/**
 * Service to connect to MongoDB
 * @var MongoDB
 */
$app['mongodb'] = $app->share( function ( Silex\Application $app ) {
	$client = new MongoClient( 'mongodb://' . $app['mongo.server.username'] . ':' . $app['mongo.server.password'] .
	                           '@' . $app['mongo.server.hostname'] . ':' . $app['mongo.server.port'] . '/' . $app['mongo.server.db']
	);
	$db     = $client->selectDB( $app['mongo.server.db'] );

	return $db;
} );

$app->register( new Silex\Provider\ValidatorServiceProvider() );
/**
 * @var \Fastwebmedia\ProfanityFilter\ProfanityFilter
 */
$app['profanity_checker'] = $app->share( function () {

	$config = require __DIR__ . '/../vendor/fastwebmedia/profanity-filter/src/config/config.php';

	$filter = new \Fastwebmedia\ProfanityFilter\ProfanityFilter( $config['words'] );

	return $filter;


} );

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/../logs/error.log',
    'monolog.level' => \Monolog\Logger::WARNING,
    'monolog.name' => 'scribbler'
));

$app->finish(function(Request $request, Response $response, Silex\Application $app) {
    if ( $response->getStatusCode() >= 400 && $response->getStatusCode() < 500 ) {
        /** @var Monolog\Logger $logger */
        $logger = $app['monolog'];
        $logger->addError( $response->getStatusCode() . ': ' . $response->getContent(), [
            'request' => $request->getContent(),
            'ip' => $request->getClientIp()
        ]);

    }
});

$app->mount( '/admin', include __DIR__ . '/admin.php' );

return $app;