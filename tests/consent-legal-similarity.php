<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Similarity check of the legal templates against texts of another generator (PLAN.md 42.14): none of our paragraphs may
 * be a copy or a close paraphrase of a paragraph of the reference.
 *
 *   php tests/consent-legal-similarity.php <directory with the reference files>
 *
 * The directory is read recursively. Reference texts are taken from:
 *   .po / .pot  every msgid and msgstr (the strings of a plugin, English source and a translation)
 *   .php        every string literal of 40+ characters (templates written as PHP arrays)
 *   .txt .html  the whole text, split into paragraphs
 * Our side: the rendered legal templates (cs and en, every optional part switched on), the purposes file, and the long
 * string literals of LegalBuilder.php (category descriptions and the like).
 *
 * Measures, per paragraph of ours (12+ words), against the paragraphs of the reference in the same language:
 *   contain4  share of our word 4-grams that occur anywhere in the reference (catches copying and light rewording)
 *   sim       similar_text() percentage against the 5 reference paragraphs that share most 3-grams (catches a close paraphrase)
 * Thresholds: a paragraph is flagged when contain4 >= 0.20 or sim >= 50. Flagged paragraphs are printed; the exit code is
 * non-zero when anything is flagged. Short common phrases (names of laws, "Article 6(1)(a) GDPR") can trigger on their own,
 * so a flag is a reason to read the paragraph, not proof of copying.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/Modules/Consent/LegalTemplate.php';

$dir = $argv[1] ?? '';
if ( '' === $dir || ! is_dir( $dir ) ) {
	fwrite( STDERR, "Usage: php tests/consent-legal-similarity.php <directory>\n" );
	exit( 2 );
}
$root = dirname( __DIR__ ) . '/includes/Modules/Consent/';
use UxStudio\Modules\Consent\LegalTemplate;

const MIN_WORDS = 12;

/** Lower-case words of a text. */
function words( string $text ): array {
	$text = mb_strtolower( html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ), 'UTF-8' );
	preg_match_all( '/[\p{L}\p{N}]+/u', $text, $m );
	return $m[0];
}
function ngrams( array $w, int $n ): array {
	$out = array();
	for ( $i = 0; $i + $n <= count( $w ); $i++ ) {
		$out[ implode( ' ', array_slice( $w, $i, $n ) ) ] = true;
	}
	return $out;
}
/** Is the text mostly Czech? (diacritics typical of Czech) */
function is_czech( string $text ): bool {
	return preg_match_all( '/[ěščřžýáíéůúťďň]/iu', $text ) > max( 3, strlen( $text ) / 60 );
}

// ---------------------------------------------------------------- reference corpus
$ref = array( 'cs' => array(), 'en' => array() );
$add = static function ( string $text ) use ( &$ref ): void {
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( count( words( $text ) ) < 6 ) {
		return;
	}
	$ref[ is_czech( $text ) ? 'cs' : 'en' ][] = $text;
};
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $files as $file ) {
	$ext = strtolower( $file->getExtension() );
	$src = (string) file_get_contents( $file->getPathname() );
	if ( 'po' === $ext || 'pot' === $ext ) {
		++$count;
		preg_match_all( '/^(?:msgid|msgstr(?:\[\d\])?)\s+((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $src, $m );
		foreach ( $m[1] as $block ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $block, $parts );
			$add( stripcslashes( implode( '', $parts[1] ) ) );
		}
	} elseif ( 'php' === $ext ) {
		++$count;
		foreach ( token_get_all( $src ) as $tok ) {
			if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] && strlen( $tok[1] ) > 42 ) {
				$add( stripcslashes( substr( $tok[1], 1, -1 ) ) );
			} elseif ( is_array( $tok ) && T_INLINE_HTML === $tok[0] ) {
				foreach ( preg_split( '/\n\s*\n/', $tok[1] ) as $p ) {
					$add( $p );
				}
			}
		}
	} elseif ( 'txt' === $ext || 'html' === $ext ) {
		++$count;
		foreach ( preg_split( '/\n\s*\n|<\/p>|<\/li>/', $src ) as $p ) {
			$add( $p );
		}
	}
}
foreach ( $ref as $lang => $list ) {
	$ref[ $lang ] = array_values( array_unique( $list ) );
}
echo "Reference: $count file(s), " . count( $ref['cs'] ) . ' Czech and ' . count( $ref['en'] ) . " English paragraphs\n";

$index = array();
foreach ( $ref as $lang => $list ) {
	$all3 = array();
	$all4 = array();
	$per  = array();
	foreach ( $list as $i => $text ) {
		$w   = words( $text );
		$g3  = ngrams( $w, 3 );
		$per[ $i ] = $g3;
		$all3     += array();
		$all4     += ngrams( $w, 4 );
		foreach ( $g3 as $g => $_ ) {
			$all3[ $g ][] = $i;
		}
	}
	$index[ $lang ] = array( 'g3' => $all3, 'g4' => $all4, 'list' => $list );
}

// ---------------------------------------------------------------- our paragraphs
$ours = array();
$take = static function ( string $html, string $lang, string $origin ) use ( &$ours ): void {
	foreach ( preg_split( '#</p>|</li>|</h[1-6]>|<br\s*/?>|\n\s*\n#i', $html ) as $piece ) {
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $piece ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( count( words( $text ) ) >= MIN_WORDS ) {
			$ours[] = array( 'lang' => $lang, 'origin' => $origin, 'text' => $text );
		}
	}
};
foreach ( array( 'cs', 'en' ) as $lang ) {
	foreach ( array( 'cookies', 'privacy', 'terms' ) as $doc ) {
		$tpl  = (string) require $root . "legal/$lang/$doc.php";
		$vars = array();
		foreach ( LegalTemplate::names( $tpl ) as $name ) {
			$vars[ $name ] = (bool) preg_match( '/^(has_|uses_|p_|return_by_|prices_|log_on)/', $name ) ? true : '[' . $name . ']';
		}
		$take( LegalTemplate::render( $tpl, $vars ), $lang, "$lang/$doc" );
	}
}
$purposes = (array) require $root . 'legal/purposes.php';
foreach ( $purposes as $key => $def ) {
	foreach ( array( 'cs', 'en' ) as $lang ) {
		foreach ( array( 'why', 'data', 'retention', 'interest' ) as $field ) {
			$text = (string) $def[ $lang ][ $field ];
			if ( count( words( $text ) ) >= 8 ) {
				$ours[] = array( 'lang' => $lang, 'origin' => "purposes/$key/$lang/$field", 'text' => $text );
			}
		}
	}
}
foreach ( token_get_all( (string) file_get_contents( $root . 'LegalBuilder.php' ) ) as $tok ) {
	if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
		$text = stripcslashes( substr( $tok[1], 1, -1 ) );
		if ( count( words( $text ) ) >= 9 ) {
			$ours[] = array( 'lang' => is_czech( $text ) ? 'cs' : 'en', 'origin' => 'LegalBuilder', 'text' => $text );
		}
	}
}

// ---------------------------------------------------------------- compare
$flagged = array();
$stats   = array( 'cs' => array( 'n' => 0, 'max4' => 0.0, 'maxsim' => 0.0 ), 'en' => array( 'n' => 0, 'max4' => 0.0, 'maxsim' => 0.0 ) );
$dist    = array( 'cs' => array(), 'en' => array() );
$closest = array();
foreach ( $ours as $p ) {
	$lang = $p['lang'];
	$w    = words( $p['text'] );
	$g4   = ngrams( $w, 4 );
	$hit  = 0;
	foreach ( $g4 as $g => $_ ) {
		if ( isset( $index[ $lang ]['g4'][ $g ] ) ) {
			++$hit;
		}
	}
	$contain = count( $g4 ) > 0 ? $hit / count( $g4 ) : 0.0;

	// Candidates: reference paragraphs that share the most 3-grams.
	$votes = array();
	foreach ( ngrams( $w, 3 ) as $g => $_ ) {
		foreach ( $index[ $lang ]['g3'][ $g ] ?? array() as $i ) {
			$votes[ $i ] = ( $votes[ $i ] ?? 0 ) + 1;
		}
	}
	arsort( $votes );
	$best = 0.0;
	foreach ( array_slice( array_keys( $votes ), 0, 5 ) as $i ) {
		similar_text( mb_strtolower( $p['text'], 'UTF-8' ), mb_strtolower( $index[ $lang ]['list'][ $i ], 'UTF-8' ), $pct );
		$best = max( $best, $pct );
	}
	// Without shared 3-grams, similar_text against random paragraphs is noise; also test the 5 nearest by length.
	if ( array() === $votes ) {
		$best = 0.0;
	}
	++$stats[ $lang ]['n'];
	$stats[ $lang ]['max4']   = max( $stats[ $lang ]['max4'], $contain );
	$stats[ $lang ]['maxsim'] = max( $stats[ $lang ]['maxsim'], $best );
	$dist[ $lang ][]          = array( $contain, $best );
	$closest[]                = array( $p, $contain, $best );
	if ( $contain >= 0.20 || $best >= 50.0 ) {
		$flagged[] = array( $p, $contain, $best );
	}
}

foreach ( array( 'cs', 'en' ) as $lang ) {
	$n    = $stats[ $lang ]['n'];
	$c4   = array_column( $dist[ $lang ], 0 );
	$sims = array_column( $dist[ $lang ], 1 );
	$avg  = static fn( array $a ): float => count( $a ) ? array_sum( $a ) / count( $a ) : 0.0;
	$zero = count( array_filter( $c4, static fn( float $v ): bool => 0.0 === $v ) );
	printf( "%s: %d paragraphs (12+ words), 4-gram containment max %.2f avg %.3f (%d with none at all), similar_text max %.1f%% avg %.1f%%\n", strtoupper( $lang ), $n, $stats[ $lang ]['max4'], $avg( $c4 ), $zero, $stats[ $lang ]['maxsim'], $avg( $sims ) );
}
echo count( $flagged ) . " paragraph(s) flagged (contain4 >= 0.20 or sim >= 50)\n";
foreach ( $flagged as [ $p, $c, $s ] ) {
	printf( "\n[%s] contain4 %.2f, sim %.1f%%\n  %s\n", $p['origin'], $c, $s, mb_substr( $p['text'], 0, 400 ) );
}
usort( $closest, static fn( array $a, array $b ): int => ( $b[1] <=> $a[1] ) ?: ( $b[2] <=> $a[2] ) );
echo "
Closest 5 (by 4-gram containment, then similar_text) (below the threshold they are not copies, shown for the record):
";
foreach ( array_slice( $closest, 0, 5 ) as [ $p, $c, $s ] ) {
	printf( "  [%s] contain4 %.2f, sim %.1f%%: %s
", $p['origin'], $c, $s, mb_substr( $p['text'], 0, 110 ) );
}
exit( array() === $flagged ? 0 : 1 );
