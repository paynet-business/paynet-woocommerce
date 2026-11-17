<?php
/**
 * Translations without WP-CLI (ТЗ этап 6).
 *
 *   php tools/i18n.php pot     — languages/paynet-for-woocommerce.pot from the PHP sources;
 *   php tools/i18n.php po      — merge the .pot into every languages/*.po (new strings empty, obsolete removed);
 *   php tools/i18n.php mo      — .mo from every languages/*.po;
 *   php tools/i18n.php check   — fails if the .pot is stale, a string is untranslated / obsolete,
 *                                placeholders differ, or a .mo does not match its .po (used in CI).
 *
 * JS has no strings of its own: texts are passed from PHP (wp_localize_script / Blocks data).
 *
 * @package Paynet_WC
 */

// phpcs:ignoreFile -- developer tool, not part of the plugin build (.distignore → tools).

const DOMAIN = 'paynet-for-woocommerce';
$root        = dirname( __DIR__ );
$lang_dir    = $root . '/languages';

// Function => argument roles.
const FUNCS = array(
	'__'           => array( 'text', 'domain' ),
	'_e'           => array( 'text', 'domain' ),
	'esc_html__'   => array( 'text', 'domain' ),
	'esc_html_e'   => array( 'text', 'domain' ),
	'esc_attr__'   => array( 'text', 'domain' ),
	'esc_attr_e'   => array( 'text', 'domain' ),
	'_x'           => array( 'text', 'context', 'domain' ),
	'_ex'          => array( 'text', 'context', 'domain' ),
	'esc_html_x'   => array( 'text', 'context', 'domain' ),
	'esc_attr_x'   => array( 'text', 'context', 'domain' ),
	'_n'           => array( 'text', 'plural', null, 'domain' ),
	'_nx'          => array( 'text', 'plural', null, 'context', 'domain' ),
);

/** Decode a PHP single/double quoted literal. */
function lit( $s ) {
	$q = $s[0];
	$b = substr( $s, 1, -1 );
	if ( "'" === $q ) {
		return str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), $b );
	}
	return stripcslashes( $b );
}

/** Strings of one file: [ key => [ msgctxt, msgid, msgid_plural, refs[], comments[] ] ]. */
function extract_file( $file, $rel, &$out, &$errors ) {
	$t = token_get_all( file_get_contents( $file ) );
	$n = count( $t );
	$comment = '';
	for ( $i = 0; $i < $n; $i++ ) {
		$tok = $t[ $i ];
		if ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			if ( false !== stripos( $tok[1], 'translators:' ) ) {
				$comment = trim( preg_replace( '#^\s*(/\*+|//|\*/?)\s*|\s*\*/\s*$#m', '', $tok[1] ) );
				$comment = preg_replace( '/\s+/', ' ', $comment );
				$comment_line = $tok[2];
			}
			continue;
		}
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] || ! isset( FUNCS[ $tok[1] ] ) ) {
			continue;
		}
		// Skip method calls / definitions.
		$p = $i - 1;
		while ( $p >= 0 && is_array( $t[ $p ] ) && T_WHITESPACE === $t[ $p ][0] ) {
			$p--;
		}
		if ( $p >= 0 && is_array( $t[ $p ] ) && in_array( $t[ $p ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $n && is_array( $t[ $j ] ) && T_WHITESPACE === $t[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $t[ $j ] ) {
			continue;
		}
		// Collect top-level arguments: each is a literal (possibly concatenated) or null (non-literal).
		$args  = array();
		$cur   = '';
		$plain = true;
		$depth = 0;
		for ( $k = $j + 1; $k < $n; $k++ ) {
			$x = $t[ $k ];
			if ( '(' === $x || '[' === $x ) {
				$depth++;
				$plain = false;
				continue;
			}
			if ( ')' === $x || ']' === $x ) {
				if ( 0 === $depth ) {
					$args[] = $plain && '' !== $cur ? $cur : null;
					break;
				}
				$depth--;
				continue;
			}
			if ( ',' === $x && 0 === $depth ) {
				$args[] = $plain && '' !== $cur ? $cur : null;
				$cur    = '';
				$plain  = true;
				continue;
			}
			if ( is_array( $x ) && T_CONSTANT_ENCAPSED_STRING === $x[0] && 0 === $depth ) {
				$cur .= lit( $x[1] );
			} elseif ( '.' === $x || ( is_array( $x ) && in_array( $x[0], array( T_WHITESPACE, T_COMMENT ), true ) ) ) {
				// concatenation / spacing.
			} else {
				$plain = false;
			}
		}
		$line  = $tok[2];
		$roles = FUNCS[ $tok[1] ];
		$v     = array();
		foreach ( $roles as $idx => $role ) {
			if ( $role ) {
				$v[ $role ] = isset( $args[ $idx ] ) ? $args[ $idx ] : null;
			}
		}
		if ( DOMAIN !== $v['domain'] ) {
			$errors[] = "$rel:$line {$tok[1]}() without the text domain " . DOMAIN;
			continue;
		}
		if ( null === $v['text'] ) {
			$errors[] = "$rel:$line {$tok[1]}() with a non-literal text";
			continue;
		}
		$ctx = isset( $v['context'] ) ? $v['context'] : '';
		$key = $ctx . "\x04" . $v['text'];
		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = array( 'ctx' => $ctx, 'id' => $v['text'], 'plural' => isset( $v['plural'] ) ? $v['plural'] : null, 'refs' => array(), 'comments' => array() );
		}
		$out[ $key ]['refs'][] = "$rel:$line";
		if ( '' !== $comment && $line - $comment_line <= 3 ) {
			$out[ $key ]['comments'][ $comment ] = true;
		}
		if ( preg_match( '/%(\d+\$)?[sd]/', $v['text'] ) && ( '' === $comment || $line - $comment_line > 3 ) ) {
			$errors[] = "$rel:$line placeholder without a translators: comment";
		}
		$comment = '';
	}
}

function po_str( $s ) {
	$e = addcslashes( $s, "\\\"\n\t" );
	return '"' . $e . '"';
}

function sources( $root ) {
	$files = array( $root . '/paynet-for-woocommerce.php', $root . '/uninstall.php' );
	foreach ( glob( $root . '/includes/*.php' ) as $f ) {
		$files[] = $f;
	}
	sort( $files );
	return $files;
}

function build_pot( $root, &$errors ) {
	$out = array();
	// Plugin header (translated by WordPress on the Plugins screen).
	$head = file_get_contents( $root . '/paynet-for-woocommerce.php' );
	foreach ( array( 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' ) as $h ) {
		if ( preg_match( '/^\s*\*\s*' . preg_quote( $h, '/' ) . ':\s*(.+)$/m', $head, $m ) ) {
			$id                     = trim( $m[1] );
			$out[ "\x04" . $id ] = array( 'ctx' => '', 'id' => $id, 'plural' => null, 'refs' => array(), 'comments' => array( "$h of the plugin" => true ) );
		}
	}
	foreach ( sources( $root ) as $f ) {
		extract_file( $f, substr( $f, strlen( $root ) + 1 ), $out, $errors );
	}
	return $out;
}

function pot_text( $entries ) {
	$s  = "# Copyright (C) Paynet Services SRL\n# This file is distributed under the GPLv2 or later.\nmsgid \"\"\nmsgstr \"\"\n";
	$s .= "\"Project-Id-Version: Paynet for WooCommerce\\n\"\n\"Report-Msgid-Bugs-To: https://github.com/paynet-business/paynet-woocommerce/issues\\n\"\n";
	$s .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: " . DOMAIN . "\\n\"\n";
	foreach ( $entries as $e ) {
		$s .= "\n";
		foreach ( array_keys( $e['comments'] ) as $c ) {
			$s .= "#. $c\n";
		}
		if ( $e['refs'] ) {
			$s .= '#: ' . implode( ' ', $e['refs'] ) . "\n";
		}
		if ( preg_match( '/%(\d+\$)?[sd]/', $e['id'] ) ) {
			$s .= "#, php-format\n";
		}
		if ( '' !== $e['ctx'] ) {
			$s .= 'msgctxt ' . po_str( $e['ctx'] ) . "\n";
		}
		$s .= 'msgid ' . po_str( $e['id'] ) . "\n";
		if ( null !== $e['plural'] ) {
			$s .= 'msgid_plural ' . po_str( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
		} else {
			$s .= "msgstr \"\"\n";
		}
	}
	return $s;
}

/** Parse a .po: [ 'headers' => string, 'entries' => [ key => [ ctx, id, plural, str[] ] ] ]. */
function parse_po( $file ) {
	$lines   = explode( "\n", str_replace( "\r\n", "\n", file_get_contents( $file ) ) );
	$entries = array();
	$cur     = null;
	$field   = null;
	$flush   = function () use ( &$cur, &$entries ) {
		if ( $cur && isset( $cur['id'] ) ) {
			$entries[ ( isset( $cur['ctx'] ) ? $cur['ctx'] : '' ) . "\x04" . $cur['id'] ] = $cur;
		}
		$cur = null;
	};
	foreach ( $lines as $l ) {
		if ( '' === trim( $l ) ) {
			$flush();
			continue;
		}
		if ( '#' === $l[0] ) {
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d)\])?)\s+"(.*)"$/', $l, $m ) ) {
			if ( 'msgctxt' === $m[1] || ( 'msgid' === $m[1] && $cur && isset( $cur['id'] ) ) ) {
				$flush();
			}
			$cur   = $cur ?: array( 'str' => array() );
			$val   = stripcslashes( $m[3] );
			$map   = array( 'msgctxt' => 'ctx', 'msgid' => 'id', 'msgid_plural' => 'plural' );
			if ( isset( $map[ $m[1] ] ) ) {
				$field          = array( $map[ $m[1] ] );
				$cur[ $field[0] ] = $val;
			} else {
				$idx              = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
				$field            = array( 'str', $idx );
				$cur['str'][ $idx ] = $val;
			}
		} elseif ( preg_match( '/^"(.*)"$/', $l, $m ) && $cur && $field ) {
			$val = stripcslashes( $m[1] );
			if ( 'str' === $field[0] ) {
				$cur['str'][ $field[1] ] .= $val;
			} else {
				$cur[ $field[0] ] .= $val;
			}
		}
	}
	$flush();
	return $entries;
}

function mo_bytes( $entries ) {
	$pairs = array();
	foreach ( $entries as $e ) {
		$id = ( isset( $e['ctx'] ) && '' !== $e['ctx'] ? $e['ctx'] . "\x04" : '' ) . $e['id'];
		if ( isset( $e['plural'] ) ) {
			$id .= "\0" . $e['plural'];
		}
		$str = implode( "\0", $e['str'] );
		if ( '' === $e['id'] || '' !== implode( '', $e['str'] ) ) {
			$pairs[ $id ] = $str;
		}
	}
	ksort( $pairs, SORT_STRING );
	$n      = count( $pairs );
	$o_tab  = 28;
	$t_tab  = $o_tab + $n * 8;
	$data   = $t_tab + $n * 8;
	$ids    = '';
	$strs   = '';
	$o_idx  = '';
	$t_idx  = '';
	foreach ( $pairs as $id => $str ) {
		$o_idx .= pack( 'VV', strlen( $id ), $data + strlen( $ids ) );
		$ids   .= $id . "\0";
	}
	$sbase = $data + strlen( $ids );
	foreach ( $pairs as $str ) {
		$t_idx .= pack( 'VV', strlen( $str ), $sbase + strlen( $strs ) );
		$strs  .= $str . "\0";
	}
	return pack( 'V7', 0x950412de, 0, $n, $o_tab, $t_tab, 0, 0 ) . $o_idx . $t_idx . $ids . $strs;
}

function placeholders( $s ) {
	preg_match_all( '/%(\d+\$)?[sd]/', $s, $m );
	$p = $m[0];
	sort( $p );
	return implode( ',', $p );
}

$cmd    = isset( $argv[1] ) ? $argv[1] : 'check';
$errors = array();
$pot    = build_pot( $root, $errors );
$potf   = $lang_dir . '/' . DOMAIN . '.pot';

if ( 'pot' === $cmd ) {
	if ( ! is_dir( $lang_dir ) ) {
		mkdir( $lang_dir );
	}
	file_put_contents( $potf, pot_text( $pot ) );
	echo count( $pot ) . " strings → languages/" . DOMAIN . ".pot\n";
}

foreach ( glob( $lang_dir . '/*.po' ) as $po ) {
	$name    = basename( $po );
	$entries = parse_po( $po );
	if ( 'po' === $cmd ) {
		$head = explode( "\n\n", str_replace( "\r\n", "\n", file_get_contents( $po ) ), 2 )[0];
		$text = $head;
		foreach ( explode( "\n\n", trim( pot_text( $pot ) ) ) as $i => $block ) {
			if ( 0 === $i ) {
				continue;
			}
			$tmp = tempnam( sys_get_temp_dir(), 'po' );
			file_put_contents( $tmp, $block . "\n" );
			$e   = array_values( parse_po( $tmp ) )[0];
			unlink( $tmp );
			$key = ( isset( $e['ctx'] ) ? $e['ctx'] : '' ) . "\x04" . $e['id'];
			$tr  = isset( $entries[ $key ] ) ? $entries[ $key ]['str'] : array();
			$lines = array_filter( explode( "\n", $block ), function ( $l ) {
				return 0 !== strpos( $l, '#:' ) && 0 !== strpos( $l, 'msgstr' );
			} );
			if ( isset( $e['plural'] ) ) {
				foreach ( array( 0, 1, 2 ) as $n ) {
					$lines[] = "msgstr[$n] " . po_str( isset( $tr[ $n ] ) ? $tr[ $n ] : '' );
				}
			} else {
				$lines[] = 'msgstr ' . po_str( isset( $tr[0] ) ? $tr[0] : '' );
			}
			$text .= "\n\n" . implode( "\n", $lines );
		}
		file_put_contents( $po, $text . "\n" );
		$entries = parse_po( $po );
		echo "$name updated\n";
	}
	$missing = 0;
	foreach ( $pot as $key => $e ) {
		if ( ! isset( $entries[ $key ] ) || '' === implode( '', $entries[ $key ]['str'] ) ) {
			$errors[] = "$name: untranslated: " . $e['id'];
			$missing++;
			continue;
		}
		foreach ( $entries[ $key ]['str'] as $tr ) {
			$src = null !== $e['plural'] ? $e['plural'] : $e['id'];
			if ( placeholders( $tr ) !== placeholders( $src ) && placeholders( $tr ) !== placeholders( $e['id'] ) ) {
				$errors[] = "$name: placeholders differ: " . $e['id'];
			}
		}
	}
	foreach ( $entries as $key => $e ) {
		if ( '' !== $e['id'] && ! isset( $pot[ $key ] ) ) {
			$errors[] = "$name: obsolete: " . $e['id'];
		}
	}
	$mo  = substr( $po, 0, -3 ) . '.mo';
	$bin = mo_bytes( $entries );
	if ( 'mo' === $cmd ) {
		file_put_contents( $mo, $bin );
		echo "$name → " . basename( $mo ) . ' (' . ( count( $entries ) - 1 ) . " strings)\n";
	} elseif ( ! is_file( $mo ) || file_get_contents( $mo ) !== $bin ) {
		$errors[] = basename( $mo ) . ' does not match its .po — run: php tools/i18n.php mo';
	}
}

if ( 'check' === $cmd && ( ! is_file( $potf ) || file_get_contents( $potf ) !== pot_text( $pot ) ) ) {
	$errors[] = 'languages/' . DOMAIN . '.pot is stale — run: php tools/i18n.php pot';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", array_unique( $errors ) ) . "\n" );
	exit( 'check' === $cmd ? 1 : 0 );
}
echo "i18n: OK (" . count( $pot ) . " strings)\n";
