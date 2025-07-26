<?php
/**
 * Plugin Name: Available Through
 * Version: 1.1.0
 * Plugin URI: https://github.com/pandamusrex/available-through
 * Description: Disable adding a product to the cart after a date
 * Author: PandamusRex
 * Author URI: https://www.github.com/pandamusrex/
 * Requires at least: 4.0
 * Tested up to: 4.0
 *
 * Text Domain: available-through
 * Domain Path: /lang/
 *
 * @package WordPress
 * @author PandamusRex
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PandamusRex_Available_Through {
    private static $instance;

    public static function get_instance() {
        if ( null == self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __clone() {}

    public function __wakeup() {}

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_postdata' ) );
        add_filter( 'woocommerce_is_purchasable', array( $this, 'woocommerce_is_purchasable' ), 10, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'woocommerce_is_purchasable' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'remove_product_from_cart' ) );
    }
  
    function add_meta_box() {
        add_meta_box( 'available_through_sectionid', __( 'Available Through', 'available-through' ), array( $this, 'meta_box' ), 'product', 'side', 'high' );
    }
    
    function is_product_purchaseable( $productID ) {
        $is_purchasable = true;

        $available_through_date = get_post_meta( $productID, '_available_through_date', true);
        # error_log("available through date = $available_through_date");
        if ( !empty( $available_through_date ) ) {
            $wp_tz = wp_timezone_string();
            # error_log("wp tz string = $wp_tz");

            $sale_ends_dt = new DateTime( "$available_through_date 23:59:59", new DateTimeZone( $wp_tz ) );
            $sale_ends_timestamp = (int)( $sale_ends_dt->format( "U" ) );
            # error_log( "sale_ends_timestamp = $sale_ends_timestamp" );

            $current_dt = new DateTime( "now", new DateTimeZone( $wp_tz ) );
            $current_timestamp = (int)( $current_dt->format("U") );
            # error_log("current_timestamp = $current_timestamp");

            if ( $sale_ends_timestamp < $current_timestamp ) {
                $is_purchasable = false;
            }
        }
        return $is_purchasable;
    }

    function meta_box( $product ) {
        echo '<input type="hidden" name="available_through_nonce" id="available_through_nonce" value="' . esc_attr( wp_create_nonce( 'available_through-' . $product->ID ) ) . '" />';

        $available_through_date = get_post_meta( $product->ID, '_available_through_date', true);

        echo esc_html__( 'Allow purchasing through', 'available-through' );
        echo '<br><br>';
        echo '<input type="text" id="_available_through_date" name="_available_through_date" value="' . esc_attr( $available_through_date ) . '" size="10" maxlength="10" />';
        echo '<br><br>';
        echo esc_html__( 'Enter date in the form mm/dd/yyyy.  Leave empty to allow perpetual sales.', 'available-through' );

        if ( !$this->is_product_purchaseable( $product->ID ) ) {
            echo '<br><br>';
            echo esc_html__( 'Note: Sales of this product have ended.', 'available-through' );
        }
    }
    
    function save_postdata( $product_id ) {
        if ( ! array_key_exists( 'available_through_nonce', $_POST ) ) {
            return $product_id;
        }

        if ( ! wp_verify_nonce( $_POST['available_through_nonce'], 'available_through-' . $product_id ) ) {
            return $product_id;
        }

        // verify if this is an auto save routine. If it is our form has not been submitted, so we dont want
        // to do anything
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            return $product_id;

        // Check permissions
        if ( 'page' == $_POST['post_type'] ) {
            if ( ! current_user_can( 'edit_page', $product_id ) )
                return $product_id;
        } else {
            if ( ! current_user_can( 'edit_post', $product_id ) )
                return $product_id;
        }

        // OK, we're authenticated: we need to find and save the data
        $user_entered_date = '';
        if ( array_key_exists( '_available_through_date', $_POST ) ) {
            $user_entered_date = $_POST['_available_through_date'];
        }

        if ( empty( $user_entered_date ) ) {
            delete_post_meta( $product_id, '_available_through_date' );
        } else {
            // Attempt to convert user entered date into unix time and then back to mm/dd/yyyy
            $timestamp = strtotime( $user_entered_date );
            if ( $timestamp ) {
                $available_through_date = strftime( "%m/%d/%Y", $timestamp );
                update_post_meta( $product_id, '_available_through_date', $available_through_date );
            } else {
                delete_post_meta( $product_id, '_available_through_date' );
            }
        }

        return $product_id;
    }

    function woocommerce_is_purchasable( $is_purchasable, $product ) {
        if ( $product->is_type('variation') ) {
            $product_id = $product->get_parent_id();
        } else {
            $product_id = $product->get_id();
        }

        if ( !$this->is_product_purchaseable( $product_id ) ) {
            $is_purchasable = false;
        }
        return $is_purchasable;
    }

    // Inspired by https://www.sitepoint.com/woocommerce-actions-and-filters-manipulate-cart/
    function remove_product_from_cart() {
        if ( ! function_exists( 'is_cart' ) ) {
            return;
        }

        if ( ! function_exists( 'is_checkout' ) ) {
            return;
        }

        // Run only in the Cart or Checkout Page
        if ( is_cart() || is_checkout() ) {
            // Cycle through each product in the cart
            foreach ( WC()->cart->cart_contents as $prod_in_cart ) {
                // Handle simple products and variable products appropriately
                // Get the Variation or Product ID
                $is_variation = ( isset( $prod_in_cart['variation_id'] ) && $prod_in_cart['variation_id'] != 0 );
                $product_id = $is_variation ? $prod_in_cart['variation_id'] : $prod_in_cart['product_id'];
                $product_id = $prod_in_cart['product_id'];
                if ( !$this->is_product_purchaseable( $product_id )) {
                    // Get it's unique ID within the Cart
                    $prod_unique_id = WC()->cart->generate_cart_id( $product_id );
                    // Remove it from the cart by un-setting it
                    unset( WC()->cart->cart_contents[$prod_unique_id] );
                }
            }
        }
    }
}

PandamusRex_Available_Through::get_instance();
