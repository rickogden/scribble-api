<?php

use \Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use \Symfony\Component\HttpFoundation\Response;
/**
 * @var Silex\Application $app
 */
$app = require_once __DIR__.'/bootstrap.php';

$app['constraints.message'] = new Assert\Collection( [
	'submitter' => new Assert\NotBlank(),
	'email'     => new Assert\Email(),
	'message'   => new Assert\NotBlank()
] );

$app->post( '/', function ( Request $request ) use ( $app ) {


	/** @var \Symfony\Component\Validator\ValidatorInterface $validator */
	$validator = $app['validator'];


	// retrieve the data from the POST request
	$data['submitter'] = $request->request->get( 'submitter', 'Anon' );
	$data['email']     = $request->request->get( 'email' );
	$data['message']   = $request->request->get( 'message' );

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

	/** @var MongoDB $mongodb */
	$mongodb = $app['mongodb'];

	$collection = $mongodb->selectCollection( 'messages' );

	// Additional data to be entered into database
	$data['ip']          = $request->getClientIp();
	$data['submitDate']  = new MongoDate();
	$data['messageType'] = 'website';

	if ( $collection->insert( $data ) ) {

		return new Response( null, 201 );

	}


	// This should never be reached unless something has gone wrong with the insert.
	$app->abort( 500, "Something has gone wrong with this application." );

} );

return $app;