<?php

require_once __DIR__.'/../vendor/autoload.php';

$app = new Silex\Application();

require_once __DIR__ . '/config.php';

/**
 * Service to connect to MongoDB
 * @var MongoDB;
 */
$app['mongodb'] = $app->share( function ( Silex\Application $app ) {
	$client = new MongoClient( 'mongodb://' . $app['mongo.server.username'] . ':' . $app['mongo.server.password'] .
	                           '@' . $app['mongo.server.hostname'] . ':' . $app['mongo.server.port'] . '/' . $app['mongo.server.db']
	);
	$db     = $client->selectDB( $app['mongo.server.db'] );

	return $db;
} );

$app->register( new Silex\Provider\ValidatorServiceProvider() );

return $app;