<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** @var \Silex\ControllerCollection $adminController */
$adminController = $app['controllers_factory'];

$adminController->before( function ( Request $request, Silex\Application $app ) {
	return new Response( 'Unauthorised', 401 );
} );

$adminController->get( '/messages', function ( Request $request ) use ( $app ) {

	$limit  = $request->query->get( 'limit', 10 );
	$offset = $request->query->get( 'offset', 0 );
	$sort   = $request->query->get( 'sort', 'ts' );
	$order  = $request->query->get( 'order', - 1 );

	$submitter   = $request->query->get( 'submitter' );
	$message     = $request->query->get( 'message' );
	$messageType = $request->query->get( 'messageType' );
	$hasPrinted  = $request->query->get( 'printed' );

	$q = [ ];

	if ( $submitter !== null ) {
		$q['submitter'] = new MongoRegex( '/' . $submitter . '/i' );
	}

	if ( $message !== null ) {
		$q['message'] = new MongoRegex( '/' . $message . '/i' );
	}

	if ( $messageType !== null ) {
		$q['messageType'] = $messageType;
	}

	if ( $hasPrinted !== null ) {
		$q['hasPrinted'] = (bool) $hasPrinted;
	}


	/** @var MongoDB $mongodb */
	$mongodb = $app['mongodb'];

	$collection = $mongodb->selectCollection( 'messages' );

	$cursor = $collection->find( $q )
	                     ->sort( [ $sort => $order ] )
	                     ->limit( $limit )
	                     ->skip( $offset );

	$results = [ ];

	foreach ( $cursor as $message ) {
		$results[] = $message;
	}

	return $app->json( $results );


} );

return $adminController;