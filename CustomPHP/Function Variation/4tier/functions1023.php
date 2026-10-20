<?php
/**
 * Child Theme Customizations for Nika Theme - Consolidated RX E-Commerce Features.
 * Includes Dual-Role Access (Rx Customer & Affiliate), Dynamic Doctor Email, Attachment, and Reporting.
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
// 2. USER ROLE RESTRICTION & ADD TO CART VALIDATION (Rx Customer / Affiliate)
// =========================================================================

/**
 * Enforces Rx Customer OR Affiliate Role for Products marked with ACF upon adding to cart.
 */
add_filter( 'woocommerce_add_to_cart_validation', 'restrict_prescription_purchase_by_role', 10, 2 );
function restrict_prescription_purchase_by_role($passed, $product_id) {
    // Checks if the product has the ACF field 'prescription_required' set to true
    if (function_exists('get_field') && get_field('prescription_required', $product_id)) { 
        
        $user = wp_get_current_user();
        // Allowed roles are Rx Customer AND Affiliate (same view/purchase capabilities)
        $allowed_roles = array('rx_customer', 'affiliate');
        $is_allowed = false;

        // Check if user is logged in and has one of the allowed roles
        if (is_user_logged_in()) {
            foreach ($allowed_roles as $role) {
                if (in_array($role, (array) $user->roles)) {
                    $is_allowed = true;
                    break;
                }
            }
        }
        
        if (!$is_allowed) {
            $passed = false; 
            $product_name = get_the_title($product_id);
            wc_add_notice( 
                sprintf( 
                    'The product "%s" is restricted. You must be an **Rx Customer** or **Affiliate** to purchase. Please log in or complete the verification process.', 
                    esc_html($product_name) 
                ), 
                'error' 
            );
        }
    }
    return $passed;
}

/**
 * Hides the Add to Cart button for unauthorized users (UX improvement).
 */
add_action('woocommerce_single_product_summary', 'hide_add_to_cart_for_unauthorized_roles', 29);
function hide_add_to_cart_for_unauthorized_roles() {
    global $product;

    if (!$product) {
        return;
    }
    
    $product_id = $product->get_id();

    if (function_exists('get_field') && get_field('prescription_required', $product_id)) {
        
        $user = wp_get_current_user();
        $allowed_roles = array('rx_customer', 'affiliate');
        $is_allowed = false;

        if (is_user_logged_in()) {
            foreach ($allowed_roles as $role) {
                if (in_array($role, (array) $user->roles)) {
                    $is_allowed = true;
                    break;
                }
            }
        }

        if (!$is_allowed) {
            
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            
            echo '<p class="must-be-rx-customer-notice"><b>This product is restricted. You must be an Rx Customer or Affiliate to proceed.</b></p>';
        }
    }
}

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
                
                // Retrieve the email address using the ACF field key 'doctor_email'
                $doctor_email = get_field( 'doctor_email', $doctor_post_id );
                break; // Stop after finding the first required doctor's email
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
    
    // Only proceed for the 'New Order' email
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
 * 3c. Injects a stylish summary of Prescription data into the email body.
 */
add_action( 'woocommerce_email_after_order_table', 'add_prescription_details_to_order_email', 10, 4 );
function add_prescription_details_to_order_email( $order, $sent_to_admin, $plain_text, $email ) {
    
    if ( $email->id !== 'new_order' ) {
        return;
    }

    // Start of the main styled container
    echo '<div style="margin-top: 25px; padding: 15px; border: 1px solid #e0e0e0; background-color: #f9f9f9;">';
    echo '<h3 style="margin-top: 0; color: #1e85be; font-size: 18px;">Prescription Requirements Summary</h3>';

    foreach ( $order->get_items() as $item_id => $item ) {
        
        $product_title = $item->get_name();
        $doctor_name = wc_get_order_item_meta( $item_id, 'Select Prescribing Doctor', true );
        $upload_url = wc_get_order_item_meta( $item_id, 'Upload Prescription (PDF/Image)', true ); 
        
        if (!empty($doctor_name) || !empty($upload_url)) {
             echo '<div style="margin-bottom: 15px; border-bottom: 1px dashed #ccc; padding-bottom: 10px;">';
             echo '<p style="margin: 5px 0;"><strong>Product:</strong> ' . esc_html($product_title) . '</p>';
             
             if (!empty($doctor_name)) {
                 echo '<p style="margin: 5px 0;"><strong>Selected Doctor:</strong> <span style="color: #000; font-weight: bold;">' . esc_html($doctor_name) . '</span></p>';
             }
             
             if (!empty($upload_url)) {
                 // Provides the link as a fallback/redundancy
                 echo '<p style="margin: 5px 0;"><strong>File Status:</strong> ATTACHED to this email (Link provided as backup)</p>';
                 echo '<p style="margin: 5px 0; font-size: 12px;"><a href="' . esc_url($upload_url) . '">Download Prescription Link</a></p>';
             }
             echo '</div>'; // End of item container
        }
    }

    echo '</div>'; // End of main container
}

// =========================================================================
// 4. ADMIN ORDER COLLATED DATA (Custom Reporting Columns)
// =========================================================================

/**
 * 4a. Add custom columns for Doctor Name and Prescription Link to the Orders list.
 */
add_filter( 'manage_edit-shop_order_columns', 'rx_add_order_columns' );
function rx_add_order_columns( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $name ) {
        $new_columns[ $key ] = $name;
        if ( 'order_number' === $key ) {
            $new_columns['rx_doctor_name'] = __( 'Doctor Name', 'woocommerce' );
            $new_columns['rx_prescription_link'] = __( 'Prescription', 'woocommerce' );
        }
    }
    return $new_columns;
}

/**
 * 4b. Populate the custom column data for each order.
 */
add_action( 'manage_shop_order_posts_custom_column', 'rx_populate_order_columns', 10, 2 );
function rx_populate_order_columns( $column, $post_id ) {
    $order = wc_get_order( $post_id );
    if ( !$order ) {
        return;
    }
    
    $doctor_name = '—';
    $prescription_url = '';
    
    foreach ( $order->get_items() as $item ) {
        $doctor_name_meta = wc_get_order_item_meta( $item->get_id(), 'Select Prescribing Doctor', true );
        $prescription_url_meta = wc_get_order_item_meta( $item->get_id(), 'Upload Prescription (PDF/Image)', true );
        
        if ( $doctor_name_meta ) {
            $doctor_name = esc_html( $doctor_name_meta );
        }
        if ( $prescription_url_meta ) {
            $prescription_url = esc_url( $prescription_url_meta );
        }
        // No break here, so if an order has multiple items with different doctors, it would show the last one processed.
    }

    switch ( $column ) {
        case 'rx_doctor_name' :
            echo $doctor_name;
            break;

        case 'rx_prescription_link' :
            if ( $prescription_url ) {
                echo '<a href="' . $prescription_url . '" target="_blank" class="button button-small">View File</a>';
            } else {
                echo 'N/A';
            }
            break;
    }
}
// Final code block ends here.