<?php
/**
 * Plugin Name:       DoughBoss Growth Media
 * Description:       Image files and manifests for the DoughBoss Growth Catering Box plugin: the box pictures and the home hero video's first-frame picture. It contains no code beyond this header. To swap images later, upload a newer zip over this one.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DoughBoss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doughboss-growth-media
 *
 * @package DoughBoss_Growth_Media
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Nothing to run. The files under assets/box are read by the DoughBoss Growth Catering Box plugin through
// assets/box/manifest.json. The manifest declares, for each image, whether it is a concept or a real photograph
// and whether it shows AI-generated food.
//
// assets/hero holds the first-frame pictures of the home hero video (0.2.0) and hero.json, which declares the same two
// facts for the video. The five MP4 files are not in this zip (the host caps an upload at 2 MB); they are uploaded under
// Media, Add New, and the Catering Box plugin finds them by file name. A file with the same name placed in assets/hero
// is used in preference to the Media Library.
