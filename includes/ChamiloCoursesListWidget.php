<?php

/**
 * Created by PhpStorm.
 * User: ywarnier
 * Date: 16/07/16
 * Time: 11:57
 */
class ChamiloCoursesListWidget extends WP_Widget
{
    function __construct() {
        // Instantiate the parent object
        parent::__construct( false, 'Chamilo Courses List widget' );
    }

    function widget( $args, $instance ) {
        $courses = chamilo_get_courses();
        chamilo_display_courses_list($courses);
    }

    function update( $new_instance, $old_instance ) {
        // Save widget options
    }

    function form( $instance ) {
        // Output admin widget options form
    }
}