# chamilo-wordpress
Chamilo integration plugin for Wordpress

## Install

To install this plugin from source, do the following inside the wp-content/plugins/ directory:
```
git clone https://github.com/chamilo/chamilo-wordpress chamilo
```

Then go to your Wordpress plugins panel and go to the "Reading" settings page.
Locate the "Chamilo connectivity" section and add your Chamilo URL (like "http://my.chamilo.net/"), the admin username ("admin" or any other username you might have set) and the security key (find it in your app/config/configuration.php by looking for "security_key").

### Configuring the courses list widget

Go to the widgets section of your Wordpress configuration panel and locate the "Chamilo Courses list" widget. Place it somewhere useful (drag & drop).

That's it! Now you can see the list of courses from your portal (depending on where you placed the widget).

### Configuring the attributes in Woocommerce to put the course code or session id to subscribe user
Go to Products/Attributes, create a new with name CHAMILOCODE and other with name CHAMILOSESSIONID

### Shortcode to list your list of courses
You can put the shortcode [chamilo_courses_list_by_user] to show a list of course of the user logged in.
To work, you need to add a few lines in /main/webservices/user_info.soap.php

define('WS_ERROR_SECRET_KEY', 1);
define('WS_ERROR_NOT_FOUND_RESULT', 2);
define('WS_ERROR_INVALID_INPUT', 3);
define('WS_ERROR_SETTING', 4);
/**
 * @param string $code
 *
 * @return null|soap_fault
 */
function returnError($code)
{
    $fault = null;
    switch ($code) {
        case WS_ERROR_SECRET_KEY:
            $fault = new soap_fault(
                'Server',
                '',
                'Secret key is not correct or params are not correctly set'
            );
            break;
        case WS_ERROR_NOT_FOUND_RESULT:
            $fault = new soap_fault(
                'Server',
                '',
                'No result was found for this query'
            );
            break;
        case WS_ERROR_INVALID_INPUT:
            $fault = new soap_fault(
                'Server',
                '',
                'The input variables are invalid o are not correctly set'
            );
            break;
        case WS_ERROR_SETTING:
            $fault = new soap_fault(
                'Server',
                '',
                'Please check the configuration for this webservice'
            );
            break;
    }

    return $fault;
}

function WSHelperVerifyKey($params)
{
    global $_configuration, $debug;
    if (is_array($params)) {
        $secret_key = $params['secret_key'];
    } else {
        $secret_key = $params;
    }
    //error_log(print_r($params,1));
    $check_ip = false;
    $ip_matches = false;
    $ip = trim($_SERVER['REMOTE_ADDR']);
    // if we are behind a reverse proxy, assume it will send the
    // HTTP_X_FORWARDED_FOR header and use this IP instead
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        list($ip1) = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ip1);
    }
    if ($debug) {
        error_log("ip: $ip");
    }
    // Check if a file that limits access from webservices exists and contains
    // the restraining check
    if (is_file('webservice-auth-ip.conf.php')) {
        include 'webservice-auth-ip.conf.php';
        if ($debug) {
            error_log("webservice-auth-ip.conf.php file included");
        }
        if (!empty($ws_auth_ip)) {
            $check_ip = true;
            $ip_matches = api_check_ip_in_range($ip, $ws_auth_ip);
            if ($debug) {
                error_log("ip_matches: $ip_matches");
            }
        }
    }

    if ($debug) {
        error_log("checkip ".intval($check_ip));
    }

    if ($check_ip) {
        $security_key = $_configuration['security_key'];
    } else {
        $security_key = $ip.$_configuration['security_key'];
        //error_log($ip.'-'.$secret_key.'-'.$security_key);
    }

    $result = api_is_valid_secret_key($secret_key, $security_key);

    if ($debug) {
        error_log('WSHelperVerifyKey result: '.intval($result));
    }

    return $result;
}
````````````
And change the function WSCourseListOfUser to that code

````````````
function WSCourseListOfUser($username, $signature)
{
  	$params['secret_key'] = $signature;
  
  	if (!WSHelperVerifyKey($params)) {
        return returnError(WS_ERROR_SECRET_KEY);
    }
  
    if (empty($username) or empty($signature)) {
        return -1;
    }
    $info = api_get_user_info_from_username($username);
    $user_id = $info['user_id'];
    

    $courses_list = [];
    $courses_list_tmp = CourseManager::get_courses_list_by_user_id($user_id);
    foreach ($courses_list_tmp as $index => $course) {
        $course_info = CourseManager::get_course_information($course['code']);
        $courses_list[] = [
            'code' => $course['code'],
            'title' => api_utf8_encode($course_info['title']),
            'url' => api_get_path(WEB_COURSE_PATH).$course_info['directory'].'/',
            'teacher' => api_utf8_encode($course_info['tutor_name']),
            'language' => $course_info['course_language'],
        ];
    }

    return $courses_list;
}

## Roadmap

In the future, we will add:
- a personal list of courses (not all the public ones but just yours)
- a way to create accounts in Chamilo when they're created in Wordpress
- a way to do Single Sign On (avoid double authentication in Wordpress and Chamilo)
