<?php
/*
Plugin Name: RX Order Viewer
Description: Custom dashboard for viewing and managing orders containing prescription products.
Version: 1.0
Author: Custom Developer
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Include the custom list table class
require_once plugin_dir_path( __FILE__ ) . 'class-rx-orders-list.php';

// Function to add the top-level admin menu item
function rx_add_menu_page() {
    add_menu_page(
        __( 'Prescription Orders', 'rx-order-viewer' ), // Page Title
        __( 'RX Orders', 'rx-order-viewer' ),           // Menu Title
        'manage_woocommerce',                            // Capability required
        'rx-orders-dashboard',                           // Menu Slug
        'rx_orders_dashboard_page',                      // Function to display content
        'dashicons-clipboard',                           // Icon URL or dashicon
        55                                               // Position in menu
    );
}
add_action( 'admin_menu', 'rx_add_menu_page' );

// Function to render the dashboard page content
function rx_orders_dashboard_page() {
    $rx_orders_list = new RX_Orders_List();
    ?>
    <div class="wrap">
        <h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
        
        <?php $rx_orders_list->prepare_items(); ?>
        
        <form id="rx-orders-filter" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php $rx_orders_list->display(); ?>
        </form>
        
    </div>
    <?php
}