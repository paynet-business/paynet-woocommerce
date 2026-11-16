<?php
/**
 * Thin Paynet Ecom API client on the WordPress HTTP API (ТЗ разд. 3, 4).
 *
 * Every call returns an array:
 *   code       HTTP status, 0 = no response (network error / timeout);
 *   data       decoded JSON or null;
 *   error      short error text for notes/admin (no personal data);
 *   error_code Paynet `code` (TECHNICAL_ERROR, NOT_FOUND_RECORD, …);
 *   trace_id   Paynet `traceId`.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * API client.
 */
class Paynet_WC_Api {

	const URL_TEST = 'https://ecom-api-test.paynet.md';
	const URL_LIVE = 'https://ecom-api.paynet.md';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * API login.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * API password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Debug logger callback or null.
	 *
	 * @var callable|null
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param bool          $test     Test environment.
	 * @param string        $username API login.
	 * @param string        $password API password.
	 * @param callable|null $logger   Logger: function( string $message ).
	 */
	public function __construct( $test, $username, $password, $logger = null ) {
		$this->base     = $test ? self::URL_TEST : self::URL_LIVE;
		$this->username = (string) $username;
		$this->password = (string) $password;
		$this->logger   = $logger;
	}

	/**
	 * Fresh `/auth` call, no cache — for the "Check connection" button.
	 *
	 * @return array
	 */
	public function check_auth() {
		$res = $this->raw( 'POST', '/auth', $this->auth_body(), null, 20 );
		if ( 200 === $res['code'] && ! empty( $res['data']['access_token'] ) ) {
			$this->store_token( $res['data'] );
		}
		return $res;
	}

	/**
	 * `POST /api/order`.
	 *
	 * @param array $body Body.
	 * @return array
	 */
	public function create_order( array $body ) {
		return $this->call( 'POST', '/api/order', $body, 30 );
	}

	/**
	 * `GET /api/order/merchant/{Order}`.
	 *
	 * @param string|int $order   Paynet Order.
	 * @param int        $timeout Timeout, seconds.
	 * @return array
	 */
	public function get_by_order( $order, $timeout = 15 ) {
		return $this->call( 'GET', '/api/order/merchant/' . rawurlencode( (string) $order ), null, $timeout );
	}

	/**
	 * Authorised call: cached token; on 401 — new token and one retry (ТЗ разд. 3).
	 *
	 * @param string     $method  Method.
	 * @param string     $path    Path.
	 * @param array|null $body    Body.
	 * @param int        $timeout Timeout.
	 * @return array
	 */
	public function call( $method, $path, $body = null, $timeout = 30 ) {
		$token = $this->token( false );
		if ( is_array( $token ) ) {
			return $token; // Auth error.
		}
		$res = $this->raw( $method, $path, $body, $token, $timeout );
		if ( 401 === $res['code'] ) {
			$token = $this->token( true );
			if ( is_array( $token ) ) {
				return $token;
			}
			$res = $this->raw( $method, $path, $body, $token, $timeout );
		}
		return $res;
	}

	/**
	 * Access token from cache or `/auth`.
	 *
	 * @param bool $force Ignore cache.
	 * @return string|array Token or error result.
	 */
	private function token( $force ) {
		$key = $this->cache_key();
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		$res = $this->raw( 'POST', '/auth', $this->auth_body(), null, 20 );
		if ( 200 !== $res['code'] || empty( $res['data']['access_token'] ) ) {
			if ( '' === $res['error'] ) {
				$res['error'] = 'Authentication failed';
			}
			return $res;
		}
		return $this->store_token( $res['data'] );
	}

	/**
	 * Cache the token until expires_in − 5 min.
	 *
	 * @param array $data Auth response.
	 * @return string
	 */
	private function store_token( array $data ) {
		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 300 : 3600;
		if ( $ttl > 60 ) {
			set_transient( $this->cache_key(), (string) $data['access_token'], $ttl );
		}
		return (string) $data['access_token'];
	}

	/**
	 * Transient key per environment and credentials.
	 *
	 * @return string
	 */
	private function cache_key() {
		return 'paynet_wc_token_' . md5( $this->base . '|' . $this->username . '|' . $this->password );
	}

	/**
	 * `/auth` body.
	 *
	 * @return array
	 */
	private function auth_body() {
		return array(
			'grantType' => 'password',
			'username'  => $this->username,
			'password'  => $this->password,
		);
	}

	/**
	 * One HTTP request.
	 *
	 * @param string      $method  Method.
	 * @param string      $path    Path.
	 * @param array|null  $body    Body.
	 * @param string|null $token   Bearer token.
	 * @param int         $timeout Timeout.
	 * @return array
	 */
	private function raw( $method, $path, $body, $token, $timeout ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( '%s %s → network error: %s', $method, $this->safe_path( $path ), $response->get_error_message() ) );
			return array(
				'code'       => 0,
				'data'       => null,
				'error'      => $response->get_error_message(),
				'error_code' => 'NETWORK',
				'trace_id'   => '',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : null;

		$res = array(
			'code'       => $code,
			'data'       => $data,
			'error'      => '',
			'error_code' => '',
			'trace_id'   => '',
		);
		if ( 200 !== $code ) {
			$res = array_merge( $res, self::parse_error( $code, $data ) );
		}
		$this->log( sprintf( '%s %s → %d %s %s', $method, $this->safe_path( $path ), $code, $res['error_code'], $res['trace_id'] ) );
		return $res;
	}

	/**
	 * Error fields from the three error shapes of the API (ErrorModel, ErrorModelUnauthorized, ErrorInfoResponse).
	 *
	 * @param int        $code HTTP code.
	 * @param array|null $data Body.
	 * @return array
	 */
	public static function parse_error( $code, $data ) {
		$data = is_array( $data ) ? $data : array();
		if ( isset( $data['errorInfo'] ) && is_array( $data['errorInfo'] ) ) {
			$info   = $data['errorInfo'];
			$errors = isset( $info['errors'] ) ? wp_json_encode( $info['errors'] ) : '';
			return array(
				'error'      => trim( ( isset( $info['title'] ) ? $info['title'] : '' ) . ' ' . (string) $errors ),
				'error_code' => isset( $info['ppResponseCodeText'] ) ? (string) $info['ppResponseCodeText'] : 'HTTP_' . $code,
				'trace_id'   => isset( $info['traceId'] ) ? (string) $info['traceId'] : '',
			);
		}
		$get = static function ( $a, $b ) use ( $data ) {
			if ( isset( $data[ $a ] ) ) {
				return (string) $data[ $a ];
			}
			return isset( $data[ $b ] ) ? (string) $data[ $b ] : '';
		};
		$message = $get( 'message', 'Message' );
		$details = $get( 'details', 'Details' );
		return array(
			'error'      => trim( $message . ( '' !== $details ? ' ' . $details : '' ) ),
			'error_code' => '' !== $get( 'code', 'Code' ) ? $get( 'code', 'Code' ) : 'HTTP_' . $code,
			'trace_id'   => $get( 'traceId', 'TraceId' ),
		);
	}

	/**
	 * Path for logs.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function safe_path( $path ) {
		return ( $this->base === self::URL_TEST ? '[test] ' : '[live] ' ) . $path;
	}

	/**
	 * Debug log.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( $this->logger ) {
			call_user_func( $this->logger, $message );
		}
	}
}
