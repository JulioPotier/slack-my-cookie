<?php
/*
Script Name: Slack My Cookie
Author: Julio Potier
Author URI: http://secupress.me
Version: 1.0.7
*/

$token = 'AZERTY123456789'; // Your token
include('vendor/autoload.php');

if ( hash_equals( $token, $_REQUEST['token'] ) ) {
	require('../wp-load.php'); // Load WordPress nested in 1 folder.
	$GLOBALS['admin_username'] = 'julio'; // Who can perform admin tasks
	$_REQUEST['channel']       = '#'.$_REQUEST['channel_name'];
	if ( 'privategroup' == $_REQUEST['channel_name'] ) {
		die( '*/cookie* is not supported in private channels. Sorry!' );
	}
	unset( $_REQUEST['channel_name'] ); // Don't send it back!
	$username                  = $_REQUEST['user_name']; // The user that triggered the command
	// Read the docs for the 7 next ones
	$_REQUEST['icon_emoji']    = ':cookie:';
	$_REQUEST['username']      = 'Cookie';
	$_REQUEST['parse']         = 'full';
	$_REQUEST['unfurl_links']  = false;
	$_REQUEST['unfurl_media']  = false;
	$_REQUEST['link_names']    = true;
	// Get our texts
	$command                   = parse_command( strtolower( $_REQUEST['text'] ), $username );
	// Share them
	$_REQUEST['text']          = $command['text'];
	$_REQUEST['attachments']   = array( 0 => $command['attachments'] );
	// Set the data
	$data                      = 'payload=' . json_encode( array_map( 'wp_unslash', $_REQUEST ) );
	// Your webhook URL
	$url                       = 'https://hooks.slack.com/services/YOURURL'; // Your webhook URL
	// Send the stuff
	wp_remote_post( $url, array( 'body' => $data ) );
}

function parse_command( $_text, $username ) {
	global $wpdb, $admin_username;
	$_text = str_replace( [ '   ', '  ', '%20', '/cookie ' ], [ ' ', ' ', ' ', '' ], $_text ); // debug

	list( $param1, $param2 ) = array_map( 'trim', explode( ' ', $_text, 2 ) );

	// Help
	if ( empty( $param1 ) && empty( $param2 ) ) {
		return array( 'text' => ':cookie: `/cookie` fonctionne comme ceci :', 'attachments' => array( 'text' => "`/cookie @someone something_else` _(pas d'espace !)_ pour donner 1 point de *something_else*.\n`/cookie @someone` Pour donner un :cookie:.\n`/cookie top @someone` Pour afficher le top des points de @someone.\n`/cookie top something` Pour afficher le top des gens sur ce mot.\n`/cookie top` Pour afficher le top 25 global.\n_Une demande ? Je suis réalisé par @{$admin_username}_ ;)" ) );
	}

	// Bypass to send messages as the bot
	if ( $admin_username === $username && '@bypass' === $param1 ) {
		return array( 'text' => $param2, 'attachments' => '' );
	}

	// Display user's stats (param1=text ; param2=name)
	if ( 'top' === $param1 && ! empty( $param2 ) ) {
		$by_pseudo = substr( $param2, 0, 1 ) === '@';
		if ( $by_pseudo ) {
			$results = $wpdb->get_results( $wpdb->prepare( 'SELECT text, points FROM slack_cookie WHERE name=%s ORDER BY points DESC, text ASC LIMIT 10', $param2 ), ARRAY_A );
			if ( $results ) {
				$content = '';
				$text    = ":cookie: Voici le TOP 10 des points de $param2 :\n";
				foreach ( $results as $i => $result ) {
					$content .= ($i+1) . ') *' . $result['text'] . '* : ' . $result['points'] . ' ' . plural_form( 'point', 'points', $result['points'] ) . "\n";
				}
				return array( 'text' => $text, 'attachments' => array( 'text' => $content ) );
			}
			echo ":no_entry_sign: Une erreur s'est produite en ligne ' . __LINE__ . '. :'(";
			return '';
		} else {
			$results = $wpdb->get_results( $wpdb->prepare( 'SELECT name, points FROM slack_cookie WHERE text=%s ORDER BY points DESC, name ASC LIMIT 10', $param2 ), ARRAY_A );
			if ( $results ) {
				$text    = ":cookie: Voici le TOP 10 des points pour *$param2* :\n";
				foreach ( $results as $i => $result ) {
					$text .= ($i+1) . ') ' . $result['name'] . ' : ' . $result['points'] . ' ' . plural_form( 'point', 'points', $result['points'] ) . "\n";
				}
				echo $text;
				return '';
			}
		}
		echo ":no_entry_sign: Une erreur s'est produite en ligne " . __LINE__ . ". :'(";
		return '';
	}

	// Top global
	if ( 'top' === $param1 && empty( $param2 ) ) {
		$results = $wpdb->get_results( 'SELECT name, text, points FROM slack_cookie ORDER BY points DESC, name ASC, text ASC LIMIT 25', ARRAY_A );
		if ( $results ) {
			$text = ":cookie: Voici le TOP 25 global :\n";
			foreach ( $results as $i => $result ) {
				$text .= ($i+1) . ') ' . $result['name'] . ' : ' . $result['points'] . ' ' . plural_form( 'point', 'points', $result['points'] ) . ' avec ' . $result['text'] . "\n";
			}
			echo $text;
			return '';
		}
		echo ":no_entry_sign: Une erreur s'est produite en ligne " . __LINE__ . ". :'(";
		return '';
	}

	// If @someone is set but no text, force a :cookie:
	if ( ! empty( $param1 ) && empty( $param2 ) ) {
		$valid = substr( $param1, 0, 1 ) === '@';
		if ( $valid ) {
			$param2 = ':cookie:';
		}
	}

	// Add score to a user (param1=name ; param2=text)
	if ( ! empty( $param1 ) && ! empty( $param2 ) ) {
		$valid = substr( $param1, 0, 1 ) === '@';
		// First param should be the name
		if ( ! $valid ) {
			echo ':no_entry_sign: Le pseudo doit commencer par "@" !';
			return '';
		}
		// Cheatin' uh?
		if ( $param1 === '@' . $username ) {
			echo ':no_entry_sign: Vous ne pouvez pas vous attribuer des points !';
			return '';
		}
		$points = $wpdb->get_var( $wpdb->prepare( 'SELECT points FROM slack_cookie WHERE name=%s AND text=%s', $param1, $param2 ) );
		// Never done, add 1 point
		if ( ! $points && 0 !== $points ) {
			$points = 1;
			$wpdb->query( $wpdb->prepare( 'INSERT INTO slack_cookie(name,text,points) VALUES (%s, %s, 1) ', $param1, $param2) );
		} else { // else add 1 more
			$points++;
			$wpdb->query( $wpdb->prepare( 'UPDATE slack_cookie SET points=%d WHERE name=%s AND text=%s', $exists, $param1, $param2 ) );
		}
		return array( 'attachments' => '', 'text' => ":cookie: $param1 a maintenant $points " . plural_form( 'point', 'points', $points ) . " de *$param2*. Merci @$username !" );
	}

}

function plural_form( $singular, $plural, $int ) {
	return (int) $int > 1 ? $plural : $singular;
}
