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

	$constraints = new Assert\Collection( [ 'fields' => [
		'submitter' => [
			new Assert\Callback( $profanityCallback ),
			new Assert\NotBlank([
                'message' => 'This can\'t be left blank. Please fill it.'
            ]),
			new Assert\Length( [ 'max' => 50 ] )
		],
		'email'     => new Assert\Email([
            'message' => 'Whoops, this doesn\'t look like a valid address.'
        ]),
		'message'   => [
			new Assert\NotBlank([
                'message' => 'This can\'t be left blank. Please fill it.'
            ]),
			new Assert\Length( [
				'max' => $app['message.limit']
			] ),
			new Assert\Callback( $profanityCallback )
		]
	],
        'allowExtraFields' => true
    ]);

	return $constraints;
} );


$validator              = function ( Request $request, Silex\Application $app ) {

    $request->request->set('message',html_entity_decode($request->request->get('message')));
    $request->request->set('submitter',html_entity_decode($request->request->get('submitter')));
	/** @var MongoDB $mongodb */
	$mongodb = $app['mongodb'];

	$collection = $mongodb->selectCollection( 'messages' );

	/** @var \Symfony\Component\Validator\ValidatorInterface $validator */
	$validator = $app['validator'];


	// retrieve the data from the POST request
	$data = $request->request->all();

    $tweet = ( isset( $data['token'] ) && $data['token'] === $app['tweet.token'] );
	// make sure not spamming
	if ( in_array( $request->getClientIp(), $app['message.throttle.blacklist'] ) ) {

		return new Response( 'You cannot create a message from this IP.', 403 );

	} else {

		if ( $tweet ) {
            if(in_array($data['submitter'], $app['message.throttle.blacklist'])) {
                return new Response( 'Twitter account forbidden.', 403 );
            } elseif (! in_array( $data['submitter'], $app['message.throttle.whitelist'] ) ) {
                $queries = $collection->find([
                    'ts' => ['$gt' => new MongoDate(time() - $app['message.throttle.seconds'])],
                    'submitter' => $data['submitter']
                ])->count();

                if ($queries > $app['message.throttle.count'] - 1) {
	                return new Response( 'Too many messages from this Twitter account.', 429 );
                }
            }
		} else {

			if ( ! in_array( $request->getClientIp(), $app['message.throttle.whitelist'] ) ) {

				$queries = $collection->find( [
					'ts' => [ '$gt' => new MongoDate( time() - $app['message.throttle.seconds'] ) ],
					'ip' => $request->getClientIp()
				] )->count();

				if ( $queries > $app['message.throttle.count'] - 1 ) {
					return new Response( 'Too many messages from this IP.', 429 );
				}
			}

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

        if($tweet) {

            /** @var MongoDB $mongodb */
            $mongodb = $app['mongodb'];

            $collection = $mongodb->selectCollection( 'messages' );

            $collection->insert([
                'submitter' => $data['submitter'],
                'email' => $data['email'],
                'message' => $data['message'],
                'hasPrinted' => true,
                'invalid' => $return
            ]);
        }

		return $app->json( [ 'errors' => $return ], 400 );
	}
};

$app->post( '/message', function ( Request $request ) use ( $app ) {

	// retrieve the data from the POST request
	$data['submitter'] = $request->request->get( 'submitter', 'Anon' );
	$data['email']     = $request->request->get( 'email' );
	$data['message']   = $request->request->get( 'message' );
	$token              = $request->request->get( 'token' );

	if ( $token !== null && $token === $app['tweet.token'] ) {
		$data['messageType'] = 'tweet';
	} else {
		$data['messageType'] = 'website';
	}

	// message type specific data
	if ( $data['messageType'] === 'tweet' ) {
        try {
            $date = new DateTime(strtotime(urldecode($request->request->get('submitDate'))));
        } catch (Exception $exception) {
            $app['monolog']->log(500,'cannot parse '. $request->request->get('submitDate'));
            $date = new DateTime();
        }
        $data['submitDate'] = $date->format( 'c' );
		$data['ts']         = new MongoDate( $date->getTimestamp() );
	} else {

		$data['submitDate'] = ( new DateTime )->format( 'c' );
		$data['ts']         = new MongoDate();
	}

	/** @var MongoDB $mongodb */
	$mongodb = $app['mongodb'];

	$collection = $mongodb->selectCollection( 'messages' );

	// Additional data to be entered into database
	$data['ip']          = $request->getClientIp();
	$data['hasPrinted'] = false;

    // Check for duplication
    if($collection->find([
        'submitter' => $data['submitter'],
        'message' => $data['message']
        ])->count() > 0) {
        $app['monolog']->log(400, 'Message already exists.',$data);
        return new Response(null, 204);
    }

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