<?php

use \Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use \Symfony\Component\HttpFoundation\Response;
use \Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @var Silex\Application $app
 */
$app = require_once __DIR__ . '/bootstrap.php';

$app['constraints.message'] = $app->share( function ( \Silex\Application $app ) {

	$profanityCallback = function ( $object, ExecutionContextInterface $meta ) use ( $app ) {

		/** @var \Fastwebmedia\ProfanityFilter\ProfanityFilter $profanityService */
		$profanityService = $app['profanity_checker'];
		if ( ! $profanityService->check( $object ) ) {
			$meta->buildViolation( 'Please don\'t swear.' )
			     ->addViolation();
		}
	};

	$constraints = new Assert\Collection( [
		'submitter' => [
			new Assert\Callback( $profanityCallback ),
			new Assert\NotBlank(),
			new Assert\Length( [ 'max' => 50 ] )
		],
		'email'     => new Assert\Email(),
		'message'   => [
			new Assert\NotBlank(),
			new Assert\Length( [
				'min' => 1,
				'max' => 240
			] ),
			new Assert\Callback( $profanityCallback )
		]
	] );

	return $constraints;
} );


$validator              = function ( Request $request, Silex\Application $app ) {

	/** @var MongoDB $mongodb */
	$mongodb = $app['mongodb'];

	$collection = $mongodb->selectCollection( 'messages' );

	/** @var \Symfony\Component\Validator\ValidatorInterface $validator */
	$validator = $app['validator'];


	// retrieve the data from the POST request
	$data = $request->request->all();

	// make sure not spamming
	if ( isset( $data['messageType'] ) ) {
		$queries = $collection->find( [
			'ts'        => [ '$gt' => new MongoDate( time() - $app['message.throttle'] ) ],
			'submitter' => $data['submitter']
		] )->count();

		if ( $queries > 0 ) {
			return new Response( 'Too many messages from this Twitter account. Please wait 10 minutes', 418 );
		}
	} else {

		$queries = $collection->find( [
			'ts' => [ '$gt' => new MongoDate( time() - $app['message.throttle'] ) ],
			'ip' => $request->getClientIp()
		] )->count();

		if ( $queries > 0 ) {
			return new Response( 'Too many messages from this IP. Please wait 10 minutes', 418 );
		}

	}

	// validate user inputted data.
	$errors = $validator->validate( $data, $app['constraints.message'] );
	if ( count( $errors ) > 0 ) {
		/** @var \Symfony\Component\Validator\ConstraintViolation $error */
		$return = [ ];
		foreach ( $errors as $error ) {
			$r        = [ 'message' => $error->getMessage(), 'field' => $error->getPropertyPath() ];
			$return[] = $r;
		}

		return $app->json( [ 'errors' => $return ], 400 );
	}
};

$app->post( '/message', function ( Request $request ) use ( $app ) {

	// retrieve the data from the POST request
	$data['submitter'] = $request->request->get( 'submitter', 'Anon' );
	$data['email']     = $request->request->get( 'email' );
	$data['message']   = $request->request->get( 'message' );
	$data['messageType'] = $request->request->get( 'messageType', 'website' );;


	/** @var MongoDB $mongodb */
	$mongodb = $app['mongodb'];

	$collection = $mongodb->selectCollection( 'messages' );

	// Additional data to be entered into database
	$data['ip']          = $request->getClientIp();
	$data['submitDate'] = ( new DateTime )->format( 'c' );
	$data['hasPrinted'] = false;
	$data['ts'] = new MongoDate();

	if ( $collection->insert( $data ) ) {

		return new Response( null, 204 );

	}


	// This should never be reached unless something has gone wrong with the insert.
	return $app->abort( 500, "Something has gone wrong with this application." );

} )->before( $validator );

$app->post( '/validate', function () use ( $app ) {
	return new Response( null, 204 );
} )->before( $validator );

return $app;