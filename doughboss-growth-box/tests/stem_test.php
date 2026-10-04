<?php
define( 'ABSPATH', '/x/' );
require dirname( __DIR__ ) . '/includes/class-dbgrbox-hero-media.php';
$cases = array(
	'2026/10/dbgr-hero-loop-720-av1.mp4'        => 'dbgr-hero-loop-720-av1',
	'2026/10/dbgr-hero-loop-720-av1-1.mp4'      => 'dbgr-hero-loop-720-av1',
	'dbgr-hero-loop-1080-hevc-12.mp4'           => 'dbgr-hero-loop-1080-hevc',
	'2026/10/dbgr-hero-loop-1080-av1-scaled.mp4' => 'dbgr-hero-loop-1080-av1',
	'2026/10/dbgr-hero-loop-720-h264-scaled-2.mp4' => 'dbgr-hero-loop-720-h264',
	'2026/10/DBGR-HERO-LOOP-720-H264.MP4'       => 'dbgr-hero-loop-720-h264',
	'2026/10/dbgr-hero-loop-720-vp9.mp4'        => '',
	'2026/10/dbgr-hero-loop-480-av1.mp4'        => '',
	'2026/10/dbgr-hero-loop-720-av1.webm'       => '',
	'2026/10/dbgr-hero-loop-720-av1-final.mp4'  => '',
	'2026/10/my-dbgr-hero-loop-720-av1.mp4'     => '',
	'2026/10/dbgr-hero-loop-720-av1.mp4.exe'    => '',
	'2026/10/dbgr-hero-poster-1080.webp'        => '',
);
$bad = 0;
foreach ( $cases as $in => $want ) {
	$got = DoughBoss_Growth_Box_Hero_Media::stem_of( $in );
	if ( $got !== $want ) { $bad++; echo "FAIL $in => '$got' (wanted '$want')\n"; }
}
echo count( $cases ) . " cases, $bad failures\n";
exit( $bad ? 1 : 0 );
