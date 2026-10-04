<?php
// Unit test of DoughBoss_Growth_Box_Hero::transform() and ::revert()-adjacent string logic, with WordPress stubbed.
define( 'ABSPATH', '/x/' );
function esc_url( $u ) { return str_replace( '&', '&#038;', $u ); }
function esc_html( $t ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function apply_filters( $n, $v ) { return $v; }
class DoughBoss_Growth_Box_Settings {}
class DoughBoss_Growth_Box_Hero_Media { public static function video_stems() { return array( 'av1-720' => 'a', 'h264-720' => 'b' ); } }
class DoughBoss_Growth_Box_Render {}
require dirname( __DIR__ ) . '/includes/class-dbgrbox-hero.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS $m\n"; } else { $fail++; echo "FAIL $m\n"; } }

$live = file_get_contents( __DIR__ . '/fixtures/live-home-hero.html' );
preg_match( '/\t\t\t\t<section class="db-manoush-hero.*?<\/section>/s', $live, $m );
$hero = $m[0];
ok( strlen( $hero ) > 1000 && false !== strpos( $hero, 'db-mh-steam' ), 'fixture: real live hero markup captured (' . strlen( $hero ) . ' bytes)' );

$vid  = DoughBoss_Growth_Box_Hero::video_html( array( 'videos' => array( 'av1-720' => array( 'url' => 'https://x.test/a.mp4?x=1&y=2' ) ) ), 'https://x.test/p.webp?ver=1' );
$chip = '<span class="dbgr-hero-chip">Concept preview</span>';
$T    = array( 'DoughBoss_Growth_Box_Hero', 'transform' );

// 1. live markup (no preload) with the poster swapped in and a band after it
$band = '<section class="dbgr-band">BAND</section>';
$out  = $T( $hero . $band, $vid, $chip );
ok( is_string( $out ), 'live markup: edited' );
ok( 1 === preg_match( '/<section class="db-manoush-hero db-manoush-hero--home has-dbgr-video" data-db-manoush-hero/', $out ), 'live markup: has-dbgr-video on the section, other attributes kept' );
ok( 1 === preg_match( '/<div class="db-mh-backdrop"[^>]*aria-hidden="true"><video class="dbgr-hero-video"[^>]*><\/video><\/div><span class="dbgr-hero-chip">Concept preview<\/span>/', $out ), 'live markup: video inside the backdrop, chip right after it' );
ok( false === strpos( $out, 'db-mh-steam' ), 'live markup: steam removed' );
ok( substr( $out, -strlen( $band ) ) === $band, 'live markup: band after the hero is untouched' );
ok( false === strpos( $out, '<source' ), 'no <source> tag anywhere' );
ok( false !== strpos( $out, 'data-av1-720="https://x.test/a.mp4?x=1&#038;y=2"' ), 'data attribute carries the escaped address' );
ok( false !== strpos( $out, 'preload="none"' ) && false !== strpos( $out, 'muted' ) && false !== strpos( $out, 'aria-hidden="true" tabindex="-1"' ), 'video attributes: muted, preload none, aria-hidden, tabindex -1' );
// everything outside the three edits is byte-identical
$back = str_replace( ' has-dbgr-video', '', $out );
$back = str_replace( $vid . '</div>' . $chip, '</div>', $back );
$expect = preg_replace( '/<div class="db-mh-steam"[^>]*>.*?<\/div>/s', '', $hero . $band, 1 );
if ( $back !== $expect ) { file_put_contents( sys_get_temp_dir() . '/dbgr-a.txt', $back ); file_put_contents( sys_get_temp_dir() . '/dbgr-b.txt', $expect ); }
ok( $back === $expect, 'live markup: apart from the 3 edits the output is byte-identical to core output' );

// 2. 2.41.0 markup: a preload link before the section
$withpre = '<link rel="preload" as="image" fetchpriority="high" href="https://x.test/p.webp?ver=1" />' . $hero;
$out2    = $T( $withpre, $vid, '' );
ok( is_string( $out2 ) && 0 === strpos( $out2, '<link rel="preload"' ) && false === strpos( $out2, 'dbgr-hero-chip' ), '2.41.0 markup (preload first): edited, preload kept, no chip when label off' );

// 3. 2.43.2 markup: backdrop is an <img> inside <picture>
$c = '<section class="db-manoush-hero db-manoush-hero--home" data-db-manoush-hero><picture><source type="image/avif" srcset="a.avif"><img class="db-mh-backdrop" src="p.jpg" alt="" loading="eager"></picture><div class="db-mh-copy"></div></section>';
ok( null === $T( $c, $vid, $chip ), '2.43.2 markup (img backdrop): fail closed (null)' );
// 4. backdrop with a child
$d = str_replace( 'aria-hidden="true"></div>', 'aria-hidden="true"><i></i></div>', $hero );
ok( null === $T( $d, $vid, $chip ), 'backdrop with content: fail closed' );
// 5. no section, wrong class, unclosed section, nested section, empty, already edited
ok( null === $T( '<div class="db-mh-backdrop"></div>', $vid, $chip ), 'no section: null' );
ok( null === $T( str_replace( 'db-manoush-hero ', 'something-else ', $hero ), $vid, $chip ), 'different class: null' );
ok( null === $T( str_replace( '</section>', '', $hero ), $vid, $chip ), 'unclosed section: null' );
ok( null === $T( str_replace( '<div class="db-mh-copy">', '<section><div class="db-mh-copy">', $hero ), $vid, $chip ), 'nested section: null' );
ok( null === $T( '', $vid, $chip ), 'empty input: null' );
ok( null === $T( $out, $vid, $chip ), 'already edited: null (no double edit)' );
// 6. two backdrops: ambiguous
$two = str_replace( '<div class="db-mh-copy">', '<div class="db-mh-backdrop"></div><div class="db-mh-copy">', $hero );
ok( null === $T( $two, $vid, $chip ), 'two backdrops: null' );
// 7. steam absent is fine
$nosteam = preg_replace( '/\s*<div class="db-mh-steam".*?<\/div>/s', '', $hero, 1 );
ok( is_string( $T( $nosteam, $vid, $chip ) ), 'no steam element: still edited' );
// 8. whitespace inside the empty backdrop is allowed
$ws = str_replace( 'aria-hidden="true"></div>', "aria-hidden=\"true\">\n\t</div>", $hero );
ok( is_string( $T( $ws, $vid, $chip ) ), 'whitespace-only backdrop: edited' );
// 9. a shortcode_atts class attribute with extra classes and single attribute order
$odd = str_replace( 'class="db-manoush-hero db-manoush-hero--home"', 'data-x="1" class="foo db-manoush-hero bar"', $hero );
$o9  = $T( $odd, $vid, $chip );
ok( is_string( $o9 ) && false !== strpos( $o9, 'class="foo db-manoush-hero bar has-dbgr-video"' ), 'class attribute not first, extra classes: edited' );
// 10. data-class must not be mistaken for class
$dc = str_replace( 'class="db-manoush-hero db-manoush-hero--home"', 'data-class="db-manoush-hero"', $hero );
ok( null === $T( $dc, $vid, $chip ), 'data-class="db-manoush-hero" is not a hero section' );

echo "\n";
echo "done $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
