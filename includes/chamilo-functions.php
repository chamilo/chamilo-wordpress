<?php
/**
 * Define a few constants
 */
define('CHAMILO_WP_PUBLIC_IP', '');
define('CHAMILO_SECRET_KEY', 1);
define('CHAMILO_PERUSER_SIGNATURE', 2);
define('CHAMILO_GLOBAL_SIGNATURE', 3);
/**
 * Basic install/uninstall functions
 */
function chamilo_install() {
    // Código de instalación
    register_setting( 'reading', 'chamilo_setting_url' );
    register_setting( 'reading', 'chamilo_setting_key' );
}
function chamilo_deactivation() {
    // Código de desactivación
}
function chamilo_uninstall() {
    // Código de desinstalación
    unregister_setting( 'chamilo_setting_url' );
    unregister_setting( 'chamilo_setting_key' );
}

/**
 * Basic settings functions
 */
function chamilo_connectivity_section_callback() {
    echo '<p>' . __( 'Please configure your global Chamilo connectivity settings', 'chamilo' ) . '</p>';
}

function chamilo_setting_url_callback_function() {
    $setting = esc_attr( get_option( 'chamilo_setting_url' ) );
    echo "<input type='text' name='chamilo_setting_url' value='$setting' />";
}

function chamilo_setting_admin_callback_function() {
    $setting = esc_attr( get_option( 'chamilo_setting_admin' ) );
    echo "<input type='text' name='chamilo_setting_admin' value='$setting' />";
}

function chamilo_setting_key_callback_function() {
    $setting = esc_attr( get_option( 'chamilo_setting_key' ) );
    echo "<input type='text' name='chamilo_setting_key' value='$setting' />";
}

function chamilo_settings_api_init() {
    add_settings_section(
        'chamilo_connectivity_section',
        __( 'Chamilo connectivity', 'chamilo' ),
        'chamilo_connectivity_section_callback',
        'reading'
    );
    add_settings_field(
        'chamilo_setting_url',
        __( 'Chamilo\'s portal url', 'chamilo' ),
        'chamilo_setting_url_callback_function',
        'reading',
        'chamilo_connectivity_section'
    );
    register_setting('reading', 'chamilo_setting_url');
    add_settings_field(
        'chamilo_setting_admin',
        __( 'Chamilo\'s admin username', 'chamilo' ),
        'chamilo_setting_admin_callback_function',
        'reading',
        'chamilo_connectivity_section'
    );
    register_setting('reading', 'chamilo_setting_admin');
    add_settings_field(
        'chamilo_setting_key',
        __( 'Chamilo\'s security key', 'chamilo' ),
        'chamilo_setting_key_callback_function',
        'reading',
        'chamilo_connectivity_section'
    );
    register_setting('reading', 'chamilo_setting_key');
}

/**
 * Basic menu functions
 */

/**
 * Get data from Chamilo
 */
function chamilo_get_courses($visibilities = array()) {
    $signature = chamilo_get_signature(CHAMILO_GLOBAL_SIGNATURE);
    $username = get_option('chamilo_setting_admin');
    if (empty($visibilites)) {
        $visibilities = 'public,public-registered';
    }
    $courses = chamilo_soap_call( 'courses_list', 'WSCourseList', 'admin', $signature, $visibilities );
    return $courses;
}

function chamilo_soap_call() {
    // Prepare params
    $params = func_get_args();
    $service = array_shift($params);
    $action = array_shift($params);
    ini_set('soap.wsdl_cache_enabled', 0);
    $services = array( 'courses_list', 'user_info', 'registration' );
    if ( !in_array( $service, $services ) ) {
        // Asking for rogue service, blocking!
        return false;
    }

    $service_path = get_option('chamilo_setting_url');
    if (substr($service_path, -1, 1) != '/') {
        $service_path .= '/';
    }
    $service_path .= 'main/webservices/' . $service . '.soap.php?wsdl';

    // Init SOAP client
    if (!empty($service_path)) {
        $client = new SoapClient($service_path);
        // Make call and its return result
        try {
            $r = $client->__soapCall($action, $params);
        } catch (Exception $e) {
            error_log('In chamilo_soap_call, exception when calling: '.$e->getMessage());
            return false;
        }
        return $r;
    } else {
        return FALSE;
    }
}

function chamilo_get_signature($type = CHAMILO_SECRET_KEY) {
    global $user;
    
    switch ($type) {
        case CHAMILO_PERUSER_SIGNATURE:
            //chamilo_load_user_data($user);
            //if (isset($user->chamilo_settings)) {
            //    return sha1($user->chamilo_settings['user'] . $user->chamilo_settings['apikey']);
            //}
            return '';
            break;
        case CHAMILO_SECRET_KEY:
            $addr = (CHAMILO_WP_PUBLIC_IP == '' ? $_SERVER['SERVER_ADDR'] : CHAMILO_WP_PUBLIC_IP);
            $chamilo_apikey = sha1( $addr . get_option( 'chamilo_setting_key' ) );
            return $chamilo_apikey;
            break;
        case CHAMILO_GLOBAL_SIGNATURE:
        default:
            $chamilo_user = get_option( 'chamilo_setting_admin' );
            $chamilo_apikey = get_option( 'chamilo_setting_key' );
            return sha1($chamilo_user . $chamilo_apikey);
            return '';
    }
}

function chamilo_get_course_visibilities() {
    return array(
        'public' => __('public', 'chamilo'),
        'private' => __('private', 'chamilo'),
        'public-registered' => __('public registered', 'chamilo'),
        'closed' => __('closed', 'chamilo')
    );
}

/**
 * Add blocks / widgets
 */

function chamilo_register_widgets() {
    register_widget( 'ChamiloCoursesListWidget' );
}

function chamilo_display_courses_list($courses) {
    $output = '';
    if (is_array($courses) && !empty($courses)) {
        $output .= '<ul>';
        foreach ($courses as $course) {
            $output .= '<li><a href="'.$course->url.'" target="_blank">'.utf8_decode($course->title).'</a> ('.$course->language.')</li>';
        }
        $output .= '</ul>';
    }
    echo $output;
}