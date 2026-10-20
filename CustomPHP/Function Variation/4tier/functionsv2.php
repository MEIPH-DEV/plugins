<?php
/**
 * @version   1.0
 * @package   nika
 * @author    Thembay Team <thembayteam@gmail.com>
 * @copyright Copyright (C) 2023 Thembay.com. All Rights Reserved.
 * @license   GNU/GPL v2 or later http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Websites: https://thembay.com
 */

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
// 1. ROLE RESTRICTION & ADD TO CART VALIDATION
// =========================================================================

/**
 * Enforce Rx Customer Role for Products marked with ACF upon adding to cart.
 */
function restrict_prescription_purchase_by_role($passed, $product_id) {
    if (function_exists('get_field') && get_field('prescription_required', $product_id)) { 
        
        $user = wp_get_current_user();
        $required_role = 'rx_customer';

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
 * Hide the Add to Cart button for unauthorized users on Rx products (UX improvement).
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
// 2. DYNAMIC EMAIL RECIPIENT & DATA INJECTION
// =========================================================================

/**
 * 2a. Adds the Doctor's email as a recipient to the 'New Order' admin email.
 */
add_filter( 'woocommerce_email_recipient_new_order', 'add_doctor_email_to_new_order', 10, 2 );

function add_doctor_email_to_new_order( $recipient, $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return $recipient;
    }
    
    $doctor_email = '';
    
    // Look for the selected doctor's name saved by WowAddons
    foreach ( $order->get_items() as $item_id => $item ) {
        // IMPORTANT: Must match the exact label used in WowAddons
        $doctor_name = wc_get_order_item_meta( $item_id, 'Select Prescribing Doctor', true );

        if ( ! empty( $doctor_name ) ) {
            // Find the Doctor Post ID using the name as the post title
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
                break; // Found the doctor, stop searching
            }
        }
    }

    if ( ! empty( $doctor_email ) && is_email( $doctor_email ) ) {
        // Appends the doctor's email to the existing admin recipient(s)
        $recipient .= ', ' . $doctor_email;
    }

    return $recipient;
}

/**
 * 2b. Injects the Prescription Upload Link and Doctor name into the email body.
 */
add_action( 'woocommerce_email_after_order_table', 'add_prescription_details_to_order_email', 10, 4 );

function add_prescription_details_to_order_email( $order, $sent_to_admin, $plain_text, $email ) {
    // Only add this content to the 'New Order' email (which is sent to the doctor/admin)
    if ( $email->id !== 'new_order' ) {
        return;
    }

    echo '<h2>Prescription & Doctor Information</h2>';
    echo '<ul>';

    foreach ( $order->get_items() as $item_id => $item ) {
        
        $product_title = $item->get_name();

        // Retrieve saved meta keys from WowAddons (Must match field labels)
        $doctor_name = wc_get_order_item_meta( $item_id, 'Select Prescribing Doctor', true );
        $upload_url = wc_get_order_item_meta( $item_id, 'Upload Prescription (PDF/Image)', true ); 
        
        // If either field has data, output the details
        if (!empty($doctor_name) || !empty($upload_url)) {
             echo '<li><strong>Product:</strong> ' . esc_html($product_title) . '</li>';
             
             if (!empty($doctor_name)) {
                 echo '<li><strong>Selected Doctor:</strong> ' . esc_html($doctor_name) . '</li>';
             }
             
             if (!empty($upload_url)) {
                 // Format the URL as a clickable link
                 echo '<li><strong>Prescription File:</strong> <a href="' . esc_url($upload_url) . '">View/Download Prescription</a></li>';
             }
             echo '<hr>';
        }
    }

    echo '</ul>';
}
/**
 * Attaches the prescription file to the WooCommerce 'New Order' email.
 * This should run ONLY when the 'New Order' email is being sent.
 */
add_filter( 'woocommerce_email_attachments', 'attach_prescription_to_new_order_email', 10, 3 );

function attach_prescription_to_new_order_email( $attachments, $email_id, $order ) {
    // Only proceed for the 'New Order' email (sent to admin/doctor)
    if ( $email_id !== 'new_order' ) {
        return $attachments;
    }

    // Loop through order items to find the prescription file URL
    foreach ( $order->get_items() as $item_id => $item ) {
        
        // IMPORTANT: Use the exact label/meta key from your WowAddons upload field
        $upload_url = wc_get_order_item_meta( $item_id, 'Upload Prescription (JPG/PNG)', true );
        
        if ( ! empty( $upload_url ) ) {
            
            // Convert the URL into a physical server path.
            // This is the tricky part and relies on standard WordPress structure.
            $upload_dir = wp_upload_dir();
            
            // Strip the base URL from the full URL to get the path relative to /uploads/
            $file_path_relative = str_replace( $upload_dir['baseurl'], '', $upload_url );
            
            // Combine the base directory with the relative path to get the absolute path
            $attachment_path = $upload_dir['basedir'] . $file_path_relative;

            // Check if the file exists on the server before attempting to attach
            if ( file_exists( $attachment_path ) ) {
                $attachments[] = $attachment_path;
            }
        }
    }

    return $attachments;
}
/**
 * Displays the selected Doctor Name on the Customer's Order/Invoice email.
 */
add_filter( 'woocommerce_order_item_meta_value', 'display_doctor_on_customer_invoice', 10, 3 );

function display_doctor_on_customer_invoice( $value, $meta, $item ) {
    
    // Check if we are in an email context (for safety)
    if ( is_admin() && ! is_ajax() ) {
        return $value;
    }

    // Check if the meta key is the one saved by WowAddons
    if ( $meta->key === 'Select Prescribing Doctor' ) {
        // Return only the doctor's name
        return $value;
    }
    
    // Check if the meta key is the one saved for the file upload (we hide this)
    if ( $meta->key === 'Upload Prescription (PDF/Image)' ) {
        // We hide the file URL from the customer, as it's confusing/unnecessary on their invoice
        return ''; 
    }

    return $value;
}
/**
 * Attaches the prescription file to the Admin, Processing, and Completed Order emails.
 */
add_filter( 'woocommerce_email_attachments', 'attach_prescription_to_order_emails', 10, 3 );

function attach_prescription_to_order_emails( $attachments, $email_id, $order ) {
    
    // Define which email IDs should receive the file attachment
    $target_email_ids = array( 
        'new_order',             // Admin / Doctor (where the dynamic recipient is added)
        'customer_processing_order', // Sent to Customer upon successful payment
        'customer_completed_order'   // Sent to Customer when order is fulfilled
    );

    // Only proceed for the target email IDs
    if ( ! in_array( $email_id, $target_email_ids ) ) {
        return $attachments;
    }

    // Loop through order items to find the prescription file URL
    foreach ( $order->get_items() as $item_id => $item ) {
        
        // IMPORTANT: Use the exact label/meta key from your WowAddons upload field
        $upload_url = wc_get_order_item_meta( $item_id, 'Upload Prescription (JPG/PNG)', true );
        
        if ( ! empty( $upload_url ) ) {
            
            // --- Convert URL into physical server path ---
            $upload_dir = wp_upload_dir();
            $file_path_relative = str_replace( $upload_dir['baseurl'], '', $upload_url );
            $attachment_path = $upload_dir['basedir'] . $file_path_relative;

            // Check if the file exists on the server
            if ( file_exists( $attachment_path ) ) {
                $attachments[] = $attachment_path;
            }
        }
    }

    return $attachments;
}