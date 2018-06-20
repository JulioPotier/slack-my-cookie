<?php
/*
Script Name: Slack My Cookie
Author: Julio Potier
Author URI: http://secupress.me
Version: 1.1

Changelog:
1.0: First release
1.1: New parsing system + code improvement
*/

/* CONFIG */
$token          = 'AZERTYUIOP1234567890'; // Your token
$admin_username = 'julio'; // Who can perform admin tasks
$more           = "\n_Une demande ? Je suis réalisé par @Julio_ ;)"; // More info in help
$channel        = false; // false will return the message in the same channel, a string will send in this particular channel.
// Your webhook URL
$url                       = 'https://hooks.slack.com/services/YOUR/URL'; // Your url

if ( hash_equals( $token, $_REQUEST['token'] ) ) {
	// The user that triggered the command
	$username = $_REQUEST['user_name'];
	// Mode maintenance, uncomment when needed
	/*
	if ( $admin_username !== $username ) {
		die( ':no_entry_sign: Mode maintenance, revenez plus tard !' );
	}
	/**/
	require( '../wp-load.php' ); // Load WordPress.
	// The bot can not write in the channel, we can't let it add cookies without feedback.
	if ( 'privategroup' == $_REQUEST['channel_name'] ) {
		die( '*/cookie* is not supported in private channels. Sorry!' );
	}
	$_REQUEST['channel'] = $channel ? '#' . $channel : '#' . $_REQUEST['channel_name'];
	// Don't send it back!
	unset( $_REQUEST['channel_name'] );
	// Read the docs for the 6 next ones
	$_REQUEST['icon_emoji']   = ':cookie:';
	$_REQUEST['username']     = 'Cookie';
	$_REQUEST['parse']        = 'full';
	$_REQUEST['unfurl_links'] = false;
	$_REQUEST['unfurl_media'] = false;
	$_REQUEST['link_names']   = true;
	// Get our texts
	$command                  = parse_command( [ 'text' => strtolower( $_REQUEST['text'] ), 'username' => $username, 'admin_username' => $admin_username, 'more' => $more ] );
	$_REQUEST['text']         = $command['text'];
	$_REQUEST['attachments']  = $command['attachments'];
	// Set the data
	$data                     = 'payload=' . json_encode( array_map( 'wp_unslash', $_REQUEST ) );
	// Send the stuff
	wp_remote_post( $url, [ 'body' => $data ] );
}

/**
 * Parse the received text and return a "text" or echoes a value.
 *
 * @return (string) The text do be displayed in the slack channel
 * @since 1.0
 * @author Julio Potier
 *
 * @param (string) $_text The text from slack /cookie command
 * @param (string) $username The username that triggered the slack /cookie command
 **/
function parse_command( $args ) { // [ 'text', 'username', 'admin_username', 'more' ]
	global $wpdb;
	// Replace some strings because token_get_all() doesn't like those ones.
	$a_before   = [ '@', ':', '.' ];
	$a_after    = [ 'å', '••', '•' ];
	// Parse the command
	$atts       = token_get_all( '<?php ' . str_replace( $a_before, $a_after, stripslashes( $args['text'] ) ) );
	array_shift( $atts );
	$atts       = array_values( array_filter( array_map( 'trim', array_column( $atts, 1 ) ) ) );
	list( $subcommand, $user, $arguments ) = array_pad( array_filter( array_merge( array_splice( $atts, 0, 2), [ $atts ] ) ), 3, '' );
	// And switch back.
	$subcommand = trim( str_replace( $a_after, $a_before, $subcommand ), '"' );
	$user       = trim( str_replace( $a_after, $a_before, $user ), '"' );

	switch ( $subcommand ) {
		// Help
		case '':
			if ( empty( $user ) ) {
				die( ":cookie: `/cookie` fonctionne comme ceci :\n`/cookie \"something here\" @someone` pour donner 1 point de *something here* à @someone.\n`/cookie @someone` Pour donner un :cookie: à @someone. _(raccourci de `/cookie :cookie: @someone`)_\n`/cookie top @someone` Pour afficher le top des points de @someone.\n`/cookie top something` Pour afficher le top des gens sur le mot *something*.\n`/cookie top` Pour afficher le top 25 global." . $args['more'] );
			}
		break;

		// Bypass to send messages as bot
		case 'chat' :
			if ( $args['admin_username'] === $args['username'] ) {
				// Just to be relevant.
				$text = $user;
				return [ 'text' => $text, 'attachments' => [] ];
			}
		break;

		// Display user's stats
		case 'top':
			// $user is really a user
			if ( ! empty( $user ) && '@' === substr( $user, 0, 1 ) ) {
				$results = $wpdb->get_results( $wpdb->prepare( 'SELECT text, points FROM slack_cookie WHERE name=%s ORDER BY points DESC, text ASC LIMIT 10', $user ), ARRAY_A );
				if ( $results ) {
					$text    = ":cookie: Voici le TOP 10 des points de $user :\n";
					foreach ( $results as $i => $result ) {
						$text .= ($i+1) . ') *' . $result['text'] . '* : ' . $result['points'] . ' ' . plural_form( $result['points'] ) . "\n";
					}
					die( $text );
				}
				die( ":no_entry_sign: $user n’a jamais reçu de cookie :'(" );
			} elseif ( ! empty( $user ) ) { // $user is, in fact, a word.
				// Just to be relevant.
				$arg     = $user;
				$results = $wpdb->get_results( $wpdb->prepare( 'SELECT name, points FROM slack_cookie WHERE text=%s ORDER BY points DESC, name ASC LIMIT 10', $arg ), ARRAY_A );
				if ( $results ) {
					$text = ":cookie: Voici le TOP 10 des points pour *$arg* :\n";
					foreach ( $results as $i => $result ) {
						$text .= ($i+1) . ') *' . $result['name'] . '* : ' . $result['points'] . ' ' . plural_form( $result['points'] ) . "\n";
					}
					die( $text );
				}
				die( ":no_entry_sign: Personne n'a jamais reçu de *$arg*. :'(" );
			} else {// Top global
				$results = $wpdb->get_results( 'SELECT name, text, points FROM slack_cookie ORDER BY points DESC, name ASC, text ASC LIMIT 25', ARRAY_A );
				if ( $results ) {
					$text = ":cookie: Voici le TOP 25 global :\n";
					foreach ( $results as $i => $result ) {
						$text .= ($i+1) . ') *' . $result['name'] . '* : ' . $result['points'] . ' ' . plural_form( $result['points'] ) . ' avec ' . $result['text'] . "\n";
					}
					die( $text );
				}
				die( ":no_entry_sign: Une erreur s'est produite en ligne " . __LINE__ . ". :'(" );
			}
		break;

		default:
			switch( $user ) {
				// This is the :cookie: shortcut
				case '':
					if ( '@' === substr( $subcommand, 0, 1 ) ) {
						$user       = $subcommand;
						$subcommand = ':cookie:';
					}
				// break; // Don't break here

				// We give points
				default:
					if ( '@' !== substr( $user, 0, 1 ) ) {
						die( ':no_entry_sign: Le pseudo doit commencer par un "@" ! Faites `/cookie` pour obtenir de l’aide.' );
					}
					// Cheatin' uh?
					if ( $user === $args['username'] ) {
						die( ':no_entry_sign: Vous ne pouvez pas vous attribuer des points !' );
					}
					$points = $wpdb->get_var( $wpdb->prepare( 'SELECT points FROM slack_cookie WHERE name=%s AND text=%s', $user, $subcommand ) );
					// Never done, add 1 point
					if ( ! $points && 0 !== $points ) {
						$points = 1;
						$wpdb->query( $wpdb->prepare( 'INSERT INTO slack_cookie(name,text,points) VALUES (%s, %s, 1) ', $user, $subcommand ) );
					} else { // else add 1 more
						$points++;
						$wpdb->query( $wpdb->prepare( 'UPDATE slack_cookie SET points=%d WHERE name=%s AND text=%s', $points, $user, $subcommand ) );
					}
					return [ 'text' => ":cookie: $user a maintenant $points " . plural_form( $points ) . " de *$subcommand* ! De la part de @{$args['username']}.", 'attachments' => [] ];
				break;
			}
		break;
	}

}

/**
 * Returns the singular or plural form string depending on given integer (works for french plural form at least, not english one sorry)
 *
 * @return (string) The correct string
 * @author Julio Potier
 * @since 1.0
 *
 * @param (int) $points The value to be checked
 * @param (string) $lang 'fr' or 'en' to select 2 different plural mode
 * @param (string) $singular The singular string value
 * @param (string) $plural The plural string value
 **/
function plural_form( $points, $lang = 'fr', $singular = 'point', $plural = 'points' ) {
	if ( 'fr' == $lang ) {
		return (int) $int > 1 ? $plural : $singular;
	} else {
		return (int) $int > 1 || 0 === (int) $int ? $plural : $singular;
	}
}
