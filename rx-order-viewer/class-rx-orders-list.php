<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class RX_Orders_List extends WP_List_Table {

    function __construct() {
        parent::__construct( array(
            'singular'  => 'rx_order',
            'plural'    => 'rx_orders',
            'ajax'      => false
        ) );
    }

    public function get_columns() {
        $columns = array(
            'cb'                => '<input type="checkbox" />',
            'order_id'          => __( 'Order', 'rx-order-viewer' ),
            'doctor_name'       => __( 'Doctor Name', 'rx-order-viewer' ),
            'prescription_link' => __( 'Prescription', 'rx-order-viewer' ),
            'order_status'      => __( 'Status', 'rx-order-viewer' ),
            'order_date'        => __( 'Date', 'rx-order-viewer' ),
            'order_total'       => __( 'Total', 'rx-order-viewer' ),
        );
        return $columns;
    }

    public function column_default( $item, $column_name ) {
        // Fallback for non-custom columns
        return isset( $item[$column_name] ) ? $item[$column_name] : '';
    }

    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            esc_attr( $this->_args['singular'] ),
            esc_attr( $item['ID'] )
        );
    }

    // Custom rendering for the 'Order' column (links to WooCommerce edit screen)
    public function column_order_id( $item ) {
        $order = wc_get_order( $item['ID'] );
        $order_number = $order ? $order->get_order_number() : 'N/A';
        $actions = array(
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $order->get_edit_order_url() ),
                'View Order #' . esc_html($order_number)
            )
        );
        return '<strong>' . $actions['edit'] . '</strong>' . $this->row_actions( $actions );
    }

    // Custom rendering for the 'Prescription Link' column
    public function column_prescription_link( $item ) {
        if ( ! empty( $item['prescription_link'] ) ) {
            return sprintf(
                '<a href="%s" target="_blank" class="button button-small">View File</a>',
                esc_url( $item['prescription_link'] )
            );
        }
        return 'N/A';
    }

    // --- Data Retrieval Logic ---

    public function prepare_items() {
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // 1. Define the meta key used by WowAddons to check if an item has prescription data
        $wowaddons_meta_key = 'Select Prescribing Doctor'; 
        
        $args = array(
            'post_type'      => 'shop_order',
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
            
            // Query orders that contain an item with the Doctor Name meta key
            'meta_query'     => array(
                array(
                    'key'     => '_order_items', // Standard WooCommerce meta key for items
                    'value'   => $wowaddons_meta_key,
                    'compare' => 'LIKE', // Search within the serialized item data
                )
            )
        );

        $query = new WP_Query( $args );
        $orders_data = array();

        foreach ( $query->posts as $post ) {
            $order = wc_get_order( $post->ID );
            if ( ! $order ) continue;

            $doctor_name = '—';
            $prescription_url = '';

            // Extract custom data from order items
            foreach ( $order->get_items() as $item ) {
                $doctor_name_meta = wc_get_order_item_meta( $item->get_id(), $wowaddons_meta_key, true );
                $prescription_url_meta = wc_get_order_item_meta( $item->get_id(), 'Upload Prescription (PDF/Image)', true );

                if ( ! empty( $doctor_name_meta ) ) {
                    $doctor_name = esc_html( $doctor_name_meta );
                }
                if ( ! empty( $prescription_url_meta ) ) {
                    $prescription_url = esc_url( $prescription_url_meta );
                }
            }
            
            // Only add the order if it actually contains the doctor data (double check)
            if ( $doctor_name !== '—' ) {
                $orders_data[] = array(
                    'ID'                => $post->ID,
                    'order_id'          => $post->ID, // Used for linking
                    'doctor_name'       => $doctor_name,
                    'prescription_link' => $prescription_url,
                    'order_status'      => wc_get_order_status_name( $order->get_status() ),
                    'order_date'        => $order->get_date_created()->date_i18n( get_option( 'date_format' ) ),
                    'order_total'       => $order->get_formatted_order_total(),
                );
            }
        }

        $this->items = $orders_data;
        
        $this->set_pagination_args( array(
            'total_items' => $query->found_posts,
            'per_page'    => $per_page,
            'total_pages' => $query->max_num_pages
        ) );
    }
}