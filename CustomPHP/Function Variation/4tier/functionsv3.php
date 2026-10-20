<?php
/**
 * Child Theme Customizations for Nika Theme - Rx E-Commerce Features.
 */

// =========================================================================
// 1. THEME STYLESHEET ENQUEUING (Nika Theme)
// =========================================================================

add_action('wp_enqueue_scripts', 'nika_child_enqueue_styles', 10000);
function nika_child_enqueue_styles() {
	$parent_style = 'nika-style';
	wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'nika-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style ),
        wp_get_theme()->get('Version')
    );
}

// =========================================================================
// 2. ROLE RESTRICTION & ADD TO CART VALIDATION (Hybrid Solution)
// =========================================================================

/**
 * Enforces Rx Customer Role for Products marked with ACF upon adding to cart.
 */
function restrict_prescription_purchase_by_role($passed, $product_id) {
    // Checks if the product has the ACF field 'prescription_required' set to true
    if (function_exists('get_field') && get_field('prescription_required', $product_id)) { 
        
        $user = wp_get_current_user();
        $required_role = 'rx_customer';

        // Check if user is not logged in OR does not have the required role
        if (!is_user_logged_in() || !in_array($required_role, (array) $user->roles)) {
            
            $passed = false; 
            $product_name = get_the_title($product_id);
            wc_add_notice( 
                sprintf( 
                    'The product "%s" requires the **Rx Customer** role for purchase. Please log in or contact support to verify your account.', 
                    esc_html($product_name) 
                ), 
                'error' 
            );
        }
    }
    return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'restrict_prescription_purchase_by_role', 10, 2 );

/**
 * Hides the Add to Cart button for unauthorized users (UX improvement).
 */
function hide_add_to_cart_for_unauthorized_roles() {
    global $product;

    if (!$product) {
        return;
    }
    
    $product_id = $product->get_id();

    if (function_exists('get_field') && get_field('prescription_required', $product_id)) {
        
        $user = wp_get_current_user();
        $required_role = 'rx_customer';

        if (!is_user_logged_in() || !in_array($required_role, (array) $user->roles)) {
            
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            
            echo '<p class="must-be-rx-customer-notice"><b>This product is restricted to *Rx Customers* only. Please log in to proceed.</b></p>';
        }
    }
}
add_action('woocommerce_single_product_summary', 'hide_add_to_cart_for_unauthorized_roles', 29);

// =========================================================================
// 3. DYNAMIC EMAIL RECIPIENT, ATTACHMENT, & DATA INJECTION
// =========================================================================

/**
 * 3a. Adds the Doctor's email as a recipient to the 'New Order' admin email.
 */
add_filter( 'woocommerce_email_recipient_new_order', 'add_doctor_email_to_new_order', 10, 2 );

function add_doctor_email_to_new_order( $recipient, $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return $recipient;
    }
    
    $doctor_email = '';
    
    foreach ( $order->get_items() as $item_id => $item ) {
        // Retrieve the selected doctor's name (WowAddons field label)
        $doctor_name = wc_get_order_item_meta( $item_id, 'Select Prescribing Doctor', true );

        if ( ! empty( $doctor_name ) ) {
            // Lookup Doctor Post ID using the name as the post title
            $doctor_posts = get_posts( array(
                'post_type'  => 'doctors',
                'title'      => $doctor_name,
                'post_status'=> 'publish',
                'fields'     => 'ids',
                'numberposts'=> 1,
            ) );

            if ( ! empty( $doctor_posts ) && function_exists('get_field') ) {
                $doctor_post_id = $doctor_posts[0];
                
                // Retrieve the email address using the ACF field key
                $doctor_email = get_field( 'doctor_email', $doctor_post_id );
                break; 
            }
        }
    }

    if ( ! empty( $doctor_email ) && is_email( $doctor_email ) ) {
        // Append the doctor's email to the existing admin recipient(s)
        $recipient .= ', ' . $doctor_email;
    }

    return $recipient;
}


/**
 * 3b. Attaches the prescription file to the 'New Order' email (Invoice copy for doctor).
 */
add_filter( 'woocommerce_email_attachments', 'attach_prescription_to_order_emails', 10, 3 );

function attach_prescription_to_order_emails( $attachments, $email_id, $order ) {
    
    // Only proceed for the 'New Order' email (which is sent to the doctor/admin)
    if ( $email_id !== 'new_order' ) {
        return $attachments;
    }

    foreach ( $order->get_items() as $item_id => $item ) {
        
        // Retrieve the uploaded file URL (WowAddons field label)
        $upload_url = wc_get_order_item_meta( $item_id, 'Upload Prescription (PDF/Image)', true );
        
        if ( ! empty( $upload_url ) ) {
            
            // Convert URL into physical server path
            $upload_dir = wp_upload_dir();
            $file_path_relative = str_replace( $upload_dir['baseurl'], '', $upload_url );
            $attachment_path = $upload_dir['basedir'] . $file_path_relative;

            // Attach the file if it exists
            if ( file_exists( $attachment_path ) ) {
                $attachments[] = $attachment_path;
            }
        }
    }

    return $attachments;
}


/**
 * 3c. Injects the Prescription link and Doctor name into the email body (visible to doctor/admin).
 */
add_action( 'woocommerce_email_after_order_table', 'add_prescription_details_to_order_email', 10, 4 );

function add_prescription_details_to_order_email( $order, $sent_to_admin, $plain_text, $email ) {
    // Only add this content to the 'New Order' email
    if ( $email->id !== 'new_order' ) {
        return;
    }

    echo '<h2>Prescription & Doctor Information</h2>';
    echo '<ul>';

    foreach ( $order->get_items() as $item_id => $item ) {
        
        $product_title = $item->get_name();
        $doctor_name = wc_get_order_item_meta( $item_id, 'Select Prescribing Doctor', true );
        $upload_url = wc_get_order_item_meta( $item_id, 'Upload Prescription (PDF/Image)', true ); 
        
        if (!empty($doctor_name) || !empty($upload_url)) {
             echo '<li><strong>Product:</strong> ' . esc_html($product_title) . '</li>';
             
             if (!empty($doctor_name)) {
                 echo '<li><strong>Selected Doctor:</strong> ' . esc_html($doctor_name) . '</li>';
             }
             
             if (!empty($upload_url)) {
                 // Provides the link as a fallback/redundancy
                 echo '<li><strong>Prescription File Link (Download Fallback):</strong> <a href="' . esc_url($upload_url) . '">View/Download Prescription</a></li>';
             }
             echo '<hr>';
        }
    }

    echo '</ul>';
}
// Do not include a closing ?> tag for safety