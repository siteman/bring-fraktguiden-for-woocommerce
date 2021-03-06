<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

include_once 'order/class-bring-wc-order-adapter.php';
include_once 'pickuppoint/class-fraktguiden-pickup-point.php';
include_once 'booking/class-bring-booking.php';

if ( Fraktguiden_Helper::get_option( 'pickup_point_enabled' ) == 'yes' ) {
  Fraktguiden_Pickup_Point::init();
}

if ( is_admin() ) {
  if ( Fraktguiden_Helper::get_option( 'booking_enabled' ) == 'yes' ) {
    Bring_Booking::init();
  }
}

# Add admin css
add_action( 'admin_enqueue_scripts', array( 'WC_Shipping_Method_Bring_Pro', 'load_admin_css' ) );

class WC_Shipping_Method_Bring_Pro extends WC_Shipping_Method_Bring {

  private $pickup_point_enabled;
  private $pickup_point_required;
  private $mybring_api_uid;
  private $mybring_api_key;
  private $booking_enabled;
  private $booking_address_store_name;
  private $booking_address_street1;
  private $booking_address_street2;
  private $booking_address_postcode;
  private $booking_address_city;
  private $booking_address_country;
  private $booking_address_reference;
  private $booking_address_contact_person;
  private $booking_address_phone;
  private $booking_address_email;
  private $booking_test_mode;

  public function __construct() {

    parent::__construct();

    $this->title        = __( 'Bring Fraktguiden Pro', 'bring-fraktguiden' );
    $this->method_title = __( 'Bring Fraktguiden Pro', 'bring-fraktguiden' );

    $this->pickup_point_enabled  = array_key_exists( 'pickup_point_enabled', $this->settings ) ? $this->settings['pickup_point_enabled'] : 'no';
    $this->pickup_point_required = array_key_exists( 'pickup_point_required', $this->settings ) ? $this->settings['pickup_point_required'] : 'no';

    $this->mybring_api_uid = array_key_exists( 'mybring_api_uid', $this->settings ) ? $this->settings['mybring_api_uid'] : '';
    $this->mybring_api_key = array_key_exists( 'mybring_api_key', $this->settings ) ? $this->settings['mybring_api_key'] : '';

    $this->booking_enabled                = array_key_exists( 'booking_enabled', $this->settings ) ? $this->settings['booking_enabled'] : 'no';
    $this->booking_address_store_name     = array_key_exists( 'booking_address_store_name', $this->settings ) ? $this->settings['booking_address_store_name'] : get_bloginfo( 'name' );
    $this->booking_address_street1        = array_key_exists( 'booking_address_street1', $this->settings ) ? $this->settings['booking_address_street1'] : '';
    $this->booking_address_street2        = array_key_exists( 'booking_address_street2', $this->settings ) ? $this->settings['booking_address_street2'] : '';
    $this->booking_address_postcode       = array_key_exists( 'booking_address_postcode', $this->settings ) ? $this->settings['booking_address_postcode'] : '';
    $this->booking_address_city           = array_key_exists( 'booking_address_city', $this->settings ) ? $this->settings['booking_address_city'] : '';
    $this->booking_address_country        = array_key_exists( 'booking_address_country', $this->settings ) ? $this->settings['booking_address_country'] : '';
    $this->booking_address_reference      = array_key_exists( 'booking_address_reference', $this->settings ) ? $this->settings['booking_address_reference'] : '';
    $this->booking_address_contact_person = array_key_exists( 'booking_address_contact_person', $this->settings ) ? $this->settings['booking_address_contact_person'] : '';
    $this->booking_address_phone          = array_key_exists( 'booking_address_phone', $this->settings ) ? $this->settings['booking_address_phone'] : '';
    $this->booking_address_email          = array_key_exists( 'booking_address_email', $this->settings ) ? $this->settings['booking_address_email'] : '';

    $this->booking_test_mode = array_key_exists( 'booking_test_mode', $this->settings ) ? $this->settings['booking_test_mode'] : 'no';

    add_filter( 'bring_shipping_rates', array( $this, 'filter_shipping_rates' ) );
  }

  public function init_form_fields() {

    global $woocommerce;

    parent::init_form_fields();

    // *************************************************************************
    // Free Shipping
    // *************************************************************************
    // $this->form_fields['free_shipping_title'] = [
    //     'type' => 'title',
    //     'title' => __( 'Free Shipping', 'bring-fraktguiden' ),
    // ];
    // $this->form_fields['free_shipping_settings'] = [
    //     'type' => 'checkbox',
    //     'title' => '',
    //     'description' => $description,
    // ];
    //

    $this->form_fields['services'] = array(
        'type' => 'services_table'
    );

    // *************************************************************************
    // Pickup Point
    // *************************************************************************

    $this->form_fields['pickup_point_title'] = [
        'type'  => 'title',
        'title' => __( 'Pickup Point Options', 'bring-fraktguiden' ),
    ];

    $this->form_fields['pickup_point_enabled'] = [
        'title'   => __( 'Enable', 'bring-fraktguiden' ),
        'type'    => 'checkbox',
        'label'   => __( 'Enable pickup point', 'bring-fraktguiden' ),
        'default' => 'no',
    ];

    $this->form_fields['pickup_point_required'] = [
        'title'   => __( 'Required', 'bring-fraktguiden' ),
        'type'    => 'checkbox',
        'label'   => __( 'Make pickup point required on checkout', 'bring-fraktguiden' ),
        'default' => 'no',
    ];

    // *************************************************************************
    // MyBring
    // *************************************************************************

    $has_api_uid_and_key = Fraktguiden_Helper::get_option( 'mybring_api_uid' ) && Fraktguiden_Helper::get_option( 'mybring_api_key' );

    $description = sprintf( __( 'In order to use Bring Booking you must be registered in <a href="%s" target="_blank">MyBring</a> and have an invoice agreement with Bring', 'bring-fraktguiden' ), 'http://mybring.com/' );
    if ( ! $has_api_uid_and_key ) {
      $description .= '<p style="font-weight: bold;color: red">' . __( 'API User ID or API Key missing!', 'bring-fraktguiden' ) . '</p>';
    }

    $this->form_fields['mybring_title'] = [
        'title'       => __( 'MyBring Account', 'bring-fraktguiden' ),
        'description' => $description,
        'type'        => 'title'
    ];

    $this->form_fields['mybring_api_uid'] = [
        'title' => __( 'API User ID', 'bring-fraktguiden' ),
        'type'  => 'text',
        'label' => __( 'API User ID', 'bring-fraktguiden' ),
    ];

    $this->form_fields['mybring_api_key'] = [
        'title' => __( 'API Key', 'bring-fraktguiden' ),
        'type'  => 'text',
        'label' => __( 'API Key', 'bring-fraktguiden' ),
    ];

    // *************************************************************************
    // Booking
    // *************************************************************************

    $this->form_fields['booking_point_title'] = [
        'title' => __( 'Booking Options', 'bring-fraktguiden' ),
        'type'  => 'title'
    ];

    $this->form_fields['booking_enabled'] = [
        'title'   => __( 'Enable', 'bring-fraktguiden' ),
        'type'    => 'checkbox',
        'label'   => __( 'Enable booking', 'bring-fraktguiden' ),
        'default' => 'no'
    ];

    $this->form_fields['booking_test_mode_enabled'] = [
        'title'       => __( 'Testing', 'bring-fraktguiden' ),
        'type'        => 'checkbox',
        'label'       => __( 'Test mode', 'bring-fraktguiden' ),
        'description' => __( 'For testing. Bookings will not be invoiced', 'bring-fraktguiden' ),
        'default'     => 'yes'
    ];

    $this->form_fields['booking_address_store_name'] = [
        'title'   => __( 'Store Name', 'bring-fraktguiden' ),
        'type'    => 'text',
        'default' => get_bloginfo( 'name' )
    ];

    $this->form_fields['booking_address_street1'] = [
        'title'             => __( 'Street Address 1', 'bring-fraktguiden' ),
        'custom_attributes' => array( 'maxlength' => '35' ),
        'type'              => 'text',
    ];

    $this->form_fields['booking_address_street2'] = [
        'title'             => __( 'Street Address 2', 'bring-fraktguiden' ),
        'custom_attributes' => array( 'maxlength' => '35' ),
        'type'              => 'text',
    ];

    $this->form_fields['booking_address_postcode'] = [
        'title' => __( 'Postcode', 'bring-fraktguiden' ),
        'type'  => 'text',
    ];

    $this->form_fields['booking_address_city'] = [
        'title' => __( 'City', 'bring-fraktguiden' ),
        'type'  => 'text',
    ];

    $this->form_fields['booking_address_country'] = [
        'title'   => __( 'Country', 'bring-fraktguiden' ),
        'type'    => 'select',
        'class'   => 'chosen_select',
        'css'     => 'width: 450px;',
        'default' => $woocommerce->countries->get_base_country(),
        'options' => $woocommerce->countries->countries
    ];

    $this->form_fields['booking_address_reference'] = [
        'title'             => __( 'Reference', 'bring-fraktguiden' ),
        'type'              => 'text',
        'custom_attributes' => array( 'maxlength' => '35' ),
        'description'       => __( 'Specify shipper or consignee reference. Available macros: {order_id}', 'bring-fraktguiden' )
    ];

    $this->form_fields['booking_address_contact_person'] = [
        'title' => __( 'Contact Person', 'bring-fraktguiden' ),
        'type'  => 'text',
    ];

    $this->form_fields['booking_address_phone'] = [
        'title' => __( 'Phone', 'bring-fraktguiden' ),
        'type'  => 'text',
    ];

    $this->form_fields['booking_address_email'] = [
        'title' => __( 'Email', 'bring-fraktguiden' ),
        'type'  => 'text',
    ];

    $this->form_fields['auto_set_status_after_booking_success'] = [
        'title'       => __( 'Order status after booking', 'bring-fraktguiden' ),
        'type'        => 'select',
        'description' => __( 'Order status to automatically set after successful booking', 'bring-fraktguiden' ),
        'class'       => 'chosen_select',
        'css'         => 'width: 450px;',
        'options'     => array( 'none' => __( 'None', 'bring-fraktguiden' ) ) + wc_get_order_statuses(),
        'default'     => 'none'
    ];
  }

  /**
   * Load admin css
   */
  static function load_admin_css() {
    $src = plugins_url( 'assets/css/admin.css', __FILE__ );
    wp_register_script( 'bfg-admin-css', $src, array(), '##VERSION##' );
    wp_enqueue_style( 'bfg-admin-css', $src, array(), '##VERSION##', false );
  }

  public function validate_services_table_field( $key, $value ) {
    return isset( $value ) ? $value : array();
  }

  public function process_admin_options() {
    parent::process_admin_options();

    // Process services table
    $services  = Fraktguiden_Helper::get_services_data();
    $field_key = $this->get_field_key( 'services' );
    $vars      = [
        'custom_prices',
        'free_shipping_checks',
        'free_shipping_thresholds',
    ];
    foreach ( $vars as $var ) {
      $$var = [];
    }
    // Only process options for enabled services
    foreach ( $services as $key => $service ) {
      foreach ( $vars as $var ) {
        $data_key = "{$field_key}_{$var}";
        if ( isset( $_POST[$data_key][$key] ) ) {
          ${$var}[$key] = $_POST[$data_key][$key];
        }
      }
    }
    foreach ( $vars as $var ) {
      $data_key = "{$field_key}_{$var}";
      update_option( $data_key, $$var );
    }

  }

  public function filter_shipping_rates( $rates ) {

    $field_key                = $this->get_field_key( 'services' );
    $custom_prices            = get_option( $field_key . '_custom_prices' );
    $free_shipping_checks     = get_option( $field_key . '_free_shipping_checks' );
    $free_shipping_thresholds = get_option( $field_key . '_free_shipping_thresholds' );
    $cart                     = WC()->cart;

    $cart_items               = $cart->get_cart();
    $cart_total               = 0;

    foreach ( $cart_items as $cart_item_key => $values ) {
      $_product = $values['data'];
      $cart_total += $_product->get_price() * $values['quantity'];
    }

    foreach ( $rates as &$rate ) {
      if ( ! preg_match( '/^bring_fraktguiden:(.+)$/', $rate['id'], $matches ) ) {
        continue;
      }
      $key = strtoupper( $matches[1] );
      if ( isset( $custom_prices[$key] ) && ctype_digit( $custom_prices[$key] ) ) {
        $rate['cost'] = floatval( $custom_prices[$key] );
      }
      if (
          isset( $free_shipping_checks[$key] ) &&
          'on' == $free_shipping_checks[$key] &&
          isset( $free_shipping_thresholds[$key] )
      ) {
        // Free shipping is checked and threshold is defined
        $threshold = $free_shipping_thresholds[$key];
        if ( ! ctype_digit( $threshold ) || $cart_total >= $threshold ) {
          // Threshold is not a number (ie. undefined) or
          // cart total is more than or equal to the threshold
          $rate['cost'] = 0;
        }
      }
    }
    // ...
    return $rates;
  }

  public function generate_services_table_html() {
    $services                 = Fraktguiden_Helper::get_services_data();
    $selected                 = $this->services;
    $field_key                = $this->get_field_key( 'services' );
    $custom_prices            = get_option( $field_key . '_custom_prices' );
    $free_shipping_checks     = get_option( $field_key . '_free_shipping_checks' );
    $free_shipping_thresholds = get_option( $field_key . '_free_shipping_thresholds' );
    ob_start();
    ?>

    <tr valign="top">
      <th scope="row" class="titledesc">
        <label
            for="<?php echo $field_key ?>"><?php _e( 'Services 2', 'bring-fraktguiden' ); ?></label>
      </th>
      <td class="forminp">
        <table class="wc_shipping widefat fraktguiden-services-table">
          <thead>
          <tr>
            <th class="fraktguiden-services-table-col-enabled">
              Aktiv
            </th>
            <th class="fraktguiden-services-table-col-service">
              Tjeneste
            </th>
            <th class="fraktguiden-services-table-col-custom-price">
              Egendefinert pris
            </th>
            <th class="fraktguiden-services-table-col-free-shipping">
              Gratis frakt
            </th>
            <th class="fraktguiden-services-table-col-free-shipping-threshold">
              Fraktfri grense
            </th>
          </tr>
          </thead>
          <tbody>

          <?php
          foreach ( $services as $key => $service ) {
            $id   = $field_key . '_' . $key;
            $vars = [
                'custom_price'            => 'custom_prices',
                'free_shipping'           => 'free_shipping_checks',
                'free_shipping_threshold' => 'free_shipping_thresholds',
            ];
            // Extract variables from the settings data
            foreach ( $vars as $var => $data_var ) {
              // Eg.: ${custom_price_id} = 'woocommerce_bring_fraktguiden_services_custom_prices[SERVICEPAKKE]';
              ${$var . '_id'} = "{$field_key}_{$data_var}[{$key}]";
              $$var           = '';
              if ( isset( ${$data_var}[$key] ) ) {
                // Eg.: $custom_price = $custom_prices['SERVICEPAKKE'];
                $$var = esc_html( ${$data_var}[$key] );
              }
            }
            $enabled = ! empty( $selected ) ? in_array( $key, $selected ) : false;
            ?>
            <tr>
              <td class="fraktguiden-services-table-col-enabled">
                <label for="<?php echo $id; ?>"
                       style="display:inline-block; width: 100%">
                  <input type="checkbox"
                         id="<?php echo $id; ?>"
                         name="<?php echo $field_key; ?>[]"
                         value="<?php echo $key; ?>" <?php echo( $enabled ? 'checked' : '' ); ?> />
                </label>
              </td>
              <td class="fraktguiden-services-table-col-name">
                <span data-tip="<?php echo $service['HelpText']; ?>"
                      class="woocommerce-help-tip"></span>
                <label class="fraktguiden-service"
                       for="<?php echo $id; ?>"
                       data-ProductName="<?php echo $service['ProductName']; ?>"
                       data-DisplayName="<?php echo $service['DisplayName']; ?>">
                  <?php echo $service[$this->service_name]; ?>
                </label>
              </td>
              <td class="fraktguiden-services-table-col-custom-price">
                <input type="text"
                       name="<?php echo $custom_price_id; ?>"
                       value="<?php echo $custom_price; ?>"
                />
              </td>
              <td class="fraktguiden-services-table-col-free-shipping">
                <label style="display:inline-block; width: 100%">
                  <input type="checkbox"
                         name="<?php echo $free_shipping_id; ?>"
                      <?php echo $free_shipping ? 'checked' : ''; ?>>
                </label>
              </td>
              <td class="fraktguiden-services-table-col-free-shipping-threshold">
                <input type="text"
                       name="<?php echo $free_shipping_threshold_id; ?>"
                       value="<?php echo $free_shipping_threshold; ?>"
                       placeholder="0"
                />
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        <script>
          jQuery( document ).ready( function () {
            var $ = jQuery;
            $( '#woocommerce_bring_fraktguiden_service_name' ).change( function () {
              console.log( 'change', this.value );
              var val = this.value;
              $( '.fraktguiden-services-table' ).find( 'label.fraktguiden-service' ).each( function ( i, elem ) {

                var label = $( elem );
                label.text( label.attr( 'data-' + val ) );
              } );
            } );

          } );
        </script>
      </td>
    </tr>

    <?php
    return ob_get_clean();
  }
}
