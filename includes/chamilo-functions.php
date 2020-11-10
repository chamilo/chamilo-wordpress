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
    $courses = chamilo_soap_call( 'courses_list', 'WSCourseList', $username, $signature, $visibilities );
    return $courses;
}

function chamilo_get_courses_by_user() {
    $signature = chamilo_get_signature(CHAMILO_SECRET_KEY);
  	$current_user = wp_get_current_user();
    $username = $current_user->user_login.$current_user->ID;
  	
    $courses = chamilo_soap_call( 'user_info', 'WSCourseListOfUser', $username, $signature);
  	
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

function chamilo_order_complete( $order_id ) {

  $finalKey = chamilo_get_signature(CHAMILO_SECRET_KEY);
  //global $items;
  $order = new WC_Order($order_id);
  //$order = wc_get_order( $order_id );
  $items = $order->get_items();
  
  foreach ( $items as $item ) {
    $product_name = $item->get_name();
    $product_id = $item->get_product_id();
    $product_variation_id = $item->get_variation_id();
    
    // Get a product instance. I could pass in an ID here.
    // I'm leaving empty to get the current product.
    $product = wc_get_product($product_id);

    
    if (!empty($product->get_attribute( 'CHAMILOCODE' ))) {
    	$courseCodeList[] = $product->get_attribute( 'CHAMILOCODE' );
    };
    if (!empty($product->get_attribute( 'CHAMILOSESSIONID' ))) {
    	$sessionList[] = $product->get_attribute( 'CHAMILOSESSIONID' );
    };
    
  }
  
  if (empty($courseCodeList) && empty($sessionList)) {
            
			//write_log( "Course code and sessionlist are empty, nothing to create" );
            return true;
  }
  
  
  // Get the user ID from an Order ID
  $user_id = get_post_meta( $order_id, '_customer_user', true );
  
  // Get an instance of the WC_Customer Object from the user ID
  $customer = new WC_Customer( $user_id );

  $username     = $customer->get_username(); // Get username
  $user_email   = $customer->get_email(); // Get account email
  $first_name   = $customer->get_first_name();
  $last_name    = $customer->get_last_name();
  $display_name = $customer->get_display_name();
 
  
  // Check if the PS customer have already an account in Chamilo
        $chamilo_params = array(
            'original_user_id_name' => 'external_user_id',
            //required, field name about original user id
            'original_user_id_value' => $user_id,
            //required, field value about original user id
            'secret_key' => $finalKey
            //required, secret key ("your IP address and security key from chamilo") encrypted with sha1
        );
  		
  		$chamilo_user_data = chamilo_soap_call( 'registration', 'WSGetUser', $chamilo_params );
  
        //write_log($chamilo_user_data);
        $chamilo_user_id = null;
        if (!empty($chamilo_user_data)) {
            $chamilo_user_id = $chamilo_user_data->user_id;
        }
  
  
  		// Login generation - firstname (30 len char) + PS customer id
        
        $login = substr(strtolower($username),0,30).$user_id;
        // User does not have a Chamilo account we proceed to create it
        if (empty($chamilo_user_id)) {
            
			//write_log( "Wordpress Customer does not exist in Chamilo proceed the creation of the Chamilo user" );
            // Password generation
            $password = $clean_password = generate_password();
          	$encryption = 'sha1';
            switch($encryption) {
                case 'md5':
                    $password = md5($password);
                    break;
                case 'sha1':
                    $password = sha1($password);
                    break;
            }

            // Default account validity in chamilo.
            $expirationDate = date('Y-m-d H:i:s', strtotime("+3660 days"));

            // Setting params
            $chamilo_params =
                array(
                    'firstname' => $first_name,   // required
                    'lastname' => $last_name,    // required
                    'status' => '5',                    // required, (1 => teacher, 5 => learner)
                    'email' => $user_email,       // optional, follow the same format (example@domain.com)
                    'loginname' => $login,                 // required
              		'official_code'=> $user_id,
                    'password' => $password,              // required, it's important to define the salt into an extra field param
                    'encrypt_method' => $encryption,  // required, check if the encrypt is the same than dokeos configuration
                    'language' => 'brazilian',              // optional
                    'phone' => '',                     // optional
                    'expiration_date' => $expirationDate,  // optional, follow the same format
                    'original_user_id_name' => 'external_user_id',  //required, field name about original user id
                    'original_user_id_value' => $user_id,          //required, field value about original user id
                    'secret_key' => $finalKey,                   //required, secret key ("your IP address and security key from chamilo") encrypted with sha1
					'extra' => array()
                );
			//write_log($chamilo_params);
            // Creating a Chamilo user, calling the webservice
          	$chamilo_user_id = chamilo_soap_call( 'registration', 'WSCreateUserPasswordCrypted', $chamilo_params);
            

            if (!empty($chamilo_user_id)) {
              	//write_log( "User is subscribed" );
                global $cookie;

                /* Email generation */
                $subject = get_bloginfo( 'name' ).' [Campus - Chamilo]';
                $templateVars = array(
                    '{firstname}' => $first_name,
                    '{lastname}' => $last_name,
                    '{email}' => $user_email,
                    '{login}' => $login,
                    '{password}' => $clean_password,
                    '{chamilo_url}' => get_option('chamilo_setting_url'),
                    '{site}' => get_bloginfo( 'name' ),
                );

                /* Email sending */

              	$to = $user_email;
                $body = $templateVars;
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
				$headers[] = 'From: CBEPJUR <'.get_bloginfo( "admin_email" ).'>';
                wp_mail( $to, $subject, $body, $headers );
              
              
                
            }else {
                
                    //write_log("Error to create user");
                
            }
            
          //write_log( "WSCreateUserPasswordCrypted was called this is the result: {$chamilo_user_id}" );
        } else {
             //write_log("User have already a chamilo account associated with the current Wordpress customer. Chamilo user_id = {$chamilo_user_id} ");

            if (!empty($chamilo_user_id)) {
                //write_log('User is subscribed');
                global $cookie;

                /* Email generation */
                $subject = get_bloginfo( 'name' ).' [Campus - Chamilo]';
                $templateVars = array(
                    '{firstname}' => $first_name,
                    '{lastname}' => $last_name,
                    '{chamilo_url}' => get_option('chamilo_setting_url'),
                    '{site}' => get_bloginfo( 'name' ),
                );

              
                /* Email sending */
                //write_log('Sending message already registered');
              
              	$to = $user_email;
                $body = $templateVars;
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
				$headers[] = 'From: CBEPJUR <'.get_bloginfo( "admin_email" ).'>';
                $mailResult = wp_mail( $to, $subject, $body, $headers );
				//write_log($mailResult);
            }
        }

        if (!empty($chamilo_user_id)) {
            foreach ($courseCodeList as $course_code) {
                write_log("Subscribing user to the course: {$course_code} ");
                //if ($this->debug) error_log('Chamilo user was registered with user_id = '.$chamilo_user_id);
                $chamilo_params = array(
                    'course' => trim($course_code),
                    'user_id' => $chamilo_user_id,
                  	'status' => STUDENT,
                    'secret_key' => $finalKey
                    //required, secret key ("your IP address and security key from chamilo") encrypted with sha1
                );
              
              	$result = chamilo_soap_call( 'registration', 'WSSubscribeUserToCourseSimple', $chamilo_params );
                

            
            }

            foreach ($sessionList as $sessionId) {
                write_log("Subscribing user to the session: {$sessionId}");

                $params = array(
                    'session' => trim($sessionId),
                    'user_id' => $chamilo_user_id,
                  	'status' => STUDENT,
                    'secret_key' => $finalKey
                );
				$result = chamilo_soap_call( 'registration', 'WSSubscribeUserToSessionSimple',$params);
                
            }
        } else {
            write_log("Error while trying to create a Chamilo user : ".print_r($chamilo_params, 1).".");
        }
        
        return true;

 
}

function generate_password($length = 8)
    {
        $characters = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        if ($length < 2) {
            $length = 2;
        }
        $password = '';
        for ($i = 0; $i < $length; $i ++) {
            $password .= $characters[rand() % strlen($characters)];
        }
        return $password;
    }

function chamilo_get_courses_by_user_display()
    {
      $courses = chamilo_get_courses_by_user();  
      chamilo_display_courses_list($courses);
    }
