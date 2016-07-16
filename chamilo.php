<?php
/*
Plugin Name: Chamilo
Description: Connect to your Chamilo portal through simple settings
Version: 1.0
Author: Yannick Warnier
Author URI: https://github.com/ywarnier
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: chamilo

{Plugin Name} is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
 {Plugin Name} is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.
  
  You should have received a copy of the GNU General Public License
  along with {Plugin Name}. If not, see {License URI}.
*/

include __DIR__ . '/includes/chamilo-functions.php';
include __DIR__ . '/includes/ChamiloCoursesListWidget.php';
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
        die;
}

//Calling hooks
register_activation_hook( __FILE__, 'chamilo_install' );
register_deactivation_hook( __FILE__, 'chamilo_deactivation' );
register_uninstall_hook( __FILE__, 'chamilo_uninstall' );

load_plugin_textdomain('chamilo', false, basename( dirname( __FILE__) ) . '/languages' );

add_action( 'admin_init', 'chamilo_settings_api_init' );
//add_action( 'admin_menu', 'chamilo_menu' );
//add_menu_page( 'options-general.php', __( 'ChamiloSettings' ), __( 'ChamiloSettings' ), 'manage_options', 'chamilo-submenu-handle', 'chamilo_menu' );

add_shortcode( 'chamilo_courses_list', 'chamilo_get_courses' );

add_action( 'widgets_init', 'chamilo_register_widgets' );