<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

// Only if Klarna is used
add_action( 'klarna_after_kco_confirmation', array( 'Fraktguiden_Pickup_Point', 'checkout_save_pickup_point' ) );
add_action( 'woocommerce_thankyou', array( 'Fraktguiden_Pickup_Point', 'checkout_save_pickup_point' ) );

/**
 * Process the checkout
 */
class Fraktguiden_Pickup_Point {

  const ID = Fraktguiden_Helper::ID;
  const BASE_URL = 'https://api.bring.com/pickuppoint/api/pickuppoint';

  static function init() {
    // Enqueue checkout Javascript.
    add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_load_javascript' ) );
    // Enqueue admin Javascript.
    add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_load_javascript' ) );
    // Checkout update order meta.
    add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'checkout_save_pickup_point' ) );
    // Admin save order items.
    add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'admin_saved_order_items' ), 1, 2 );
    // Ajax
    add_action( 'wp_ajax_bring_get_pickup_points', array( __CLASS__, 'wp_ajax_get_pickup_points' ) );
    add_action( 'wp_ajax_nopriv_bring_get_pickup_points', array( __CLASS__, 'wp_ajax_get_pickup_points' ) );

    add_action( 'wp_ajax_bring_shipping_info_var', array( __CLASS__, 'wp_ajax_get_bring_shipping_info_var' ) );
    add_action( 'wp_ajax_bring_get_rate', array( __CLASS__, 'wp_ajax_get_rate' ) );

    // Validate pickup point.
    if ( Fraktguiden_Helper::get_option( 'pickup_point_required' ) == 'yes' ) {
      add_action( 'woocommerce_checkout_process', array( __CLASS__, 'checkout_validate_pickup_point' ) );
    }

    // Display order received and mail.
    add_filter( 'woocommerce_order_shipping_to_display_shipped_via', array( __CLASS__, 'checkout_order_shipping_to_display_shipped_via' ), 1, 2 );

    // Hide shipping meta data from order items (WooCommerce 2.6)
    // https://github.com/woothemes/woocommerce/issues/9094
    add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'woocommerce_hidden_order_itemmeta' ), 1, 1 );

    // Klarna checkout specific html
    add_action( 'kco_after_cart', array( __CLASS__, 'kco_post_code_html' ) );
    add_action( 'kco_after_cart', array( __CLASS__, 'kco_pickuppoint_html' ), 11 );
    add_filter( 'kco_create_order', array( __CLASS__, 'set_kco_postal_code' ) );
    add_filter( 'kco_update_order', array( __CLASS__, 'set_kco_postal_code' ) );
  }

  /**
   * Set Klarna Checkout postal code
   * @param array Klarna order data
   */
  static function set_kco_postal_code( $order ) {
    $postcode = esc_html( WC()->customer->get_shipping_postcode() );
    $order['shipping_address']['postal_code'] = $postcode;
    return $order;
  }

  /**
   * Klarna Checkout post code selector HTML
   */
  static function kco_post_code_html() {
    $i18n = self::get_i18n();
    $postcode = esc_html( WC()->customer->get_shipping_postcode() );
    ?>
    <div class="bring-enter-postcode">
      <form>
        <label><?php echo $i18n['POSTCODE']; ?>
          <input class="input-text" type="text" value="<?php echo $postcode; ?>">
        </label>
        <input class="button" type="submit" value="Hent Leveringsmetoder">
      </form>
    </div>
    <?php
  }

  /**
   * Klarna Checkout pickup point HTML
   */
  static function kco_pickuppoint_html() {
    $i18n               = self::get_i18n();
    $postcode           = esc_html( WC()->customer->get_shipping_postcode() );
    $selected_id        = trim( @$_COOKIE['_fraktguiden_pickup_point_id'] );
    $options            = '';
    $postcodes          = array();
    $pickup_point_limit = apply_filters( 'bring_pickup_point_limit', 999 );
    $pickup_point_count = 0;
    if ( $postcode ) {
      $response = self::get_pickup_points( 'NO', $postcode );
      if ( 200 == $response->status_code ) {
        $pickup_points = json_decode( $response->get_body() );
        foreach ( $pickup_points->pickupPoint as $pickup_point ) {
          if ( ! $selected_id || 'undefined' == $selected_id ) {
            $selected_id = $pickup_point->id;
          }
          $postcodes[ $pickup_point->id ] = [
            'name' => esc_html( $pickup_point->name ),
            'data' => esc_html( json_encode( $pickup_point ) ),
          ];
          if ( ++$pickup_point_count >= $pickup_point_limit ) {
            break;
          }
        }
      }
    }

    foreach ( $postcodes as $key => $value ) {
      $selected = '';
      if ( $selected_id == $key ) {
        $selected = 'checked="checked"';
      }
      $options .= sprintf(
        '<li><input name="bring_method" type="radio" value="%s" %s data-pickup_point="%s" id="%1$s"> <label for="%1$s">%s</label></li>',
        $key,
        $selected,
        $value['data'],
        $value['name']
      );
    }
    ?>
    <div class="fraktguiden-pickup-point" style="display: none">
      <ul class="fraktguiden-pickup-point-list">
        <?php echo $options; ?>
      </ul>
      <div class="fraktguiden-selected-text"></div>
      <div class="fraktguiden-pickup-point-display"></div>
      <input type="hidden" name="_fraktguiden_pickup_point_info_cached"/>
    </div>
    <?php
  }

  /**
   * Load checkout javascript
   */
  static function checkout_load_javascript() {

    if ( is_checkout() ) {
      wp_register_script( 'fraktguiden-common', plugins_url( 'assets/js/pickup-point-common.js', dirname( __FILE__ ) ), array( 'jquery' ), '##VERSION##', true );
      wp_register_script( 'fraktguiden-pickup-point-checkout', plugins_url( 'assets/js/pickup-point-checkout.js', dirname( __FILE__ ) ), array( 'jquery' ), '##VERSION##', true );
      wp_localize_script( 'fraktguiden-pickup-point-checkout', '_fraktguiden_data', [
          'ajaxurl'      => admin_url( 'admin-ajax.php' ),
          'i18n'         => self::get_i18n(),
          'from_country' => Fraktguiden_Helper::get_option( 'from_country' )
      ] );

      wp_enqueue_script( 'fraktguiden-common' );
      wp_enqueue_script( 'fraktguiden-pickup-point-checkout' );
    }
  }

  /**
   * Load admin javascript
   */
  static function admin_load_javascript() {
    $screen = get_current_screen();
    // Only for order edit screen
    if ( $screen->id == 'shop_order' ) {
      global $post;
      $order = new Bring_WC_Order_Adapter( new WC_Order( $post->ID ) );

      $make_items_editable = ! $order->order->is_editable();
      if ( isset( $_GET['booking_step'] ) ) {
        $make_items_editable = false;
      }

      if ( $order->is_booked() ) {
        $make_items_editable = false;
      }

      wp_register_script( 'fraktguiden-common', plugins_url( 'assets/js/pickup-point-common.js', dirname( __FILE__ ) ), array( 'jquery' ), '##VERSION##', true );
      wp_register_script( 'fraktguiden-pickup-point-admin', plugins_url( 'assets/js/pickup-point-admin.js', dirname( __FILE__ ) ), array( 'jquery' ), '##VERSION##', true );
      wp_localize_script( 'fraktguiden-pickup-point-admin', '_fraktguiden_data', [
          'ajaxurl'             => admin_url( 'admin-ajax.php' ),
          'services'            => Fraktguiden_Helper::get_all_services(),
          'i18n'                => self::get_i18n(),
          'make_items_editable' => $make_items_editable
      ] );

      wp_enqueue_script( 'fraktguiden-common' );
      wp_enqueue_script( 'fraktguiden-pickup-point-admin' );
    }
  }

  static function get_bring_shipping_info_for_order() {
    $result = [ ];
    $screen = get_current_screen();
    if ( ( $screen && $screen->id == 'shop_order' ) || is_ajax() ) {
      global $post;
      $post_id = $post ? $post->ID : $_GET['post_id'];
      $order   = new Bring_WC_Order_Adapter( new WC_Order( $post_id ) );
      $result  = $order->get_shipping_data();
    }
    return $result;
  }

  /**
   * Validate pickup point on checkout submit.
   */
  static function checkout_validate_pickup_point() {
    // Check if set, if its not set add an error.
    if ( ! $_COOKIE['_fraktguiden_pickup_point_id'] ) {
      wc_add_notice( __( '<strong>Pickup point</strong> is a required field.', 'bring-fraktguiden' ), 'error' );
    }
  }

  /**
   * Add pickup point from shop/checkout
   *
   * This method now assumes that the system has only one shipping method per order in checkout.
   *
   * @param int $order_id
   */
  static function checkout_save_pickup_point( $order_id ) {

    if ( $order_id ) {

      $order = new Bring_WC_Order_Adapter( new WC_Order( $order_id ) );

      $expire = time() - 300;

      if ( isset( $_COOKIE['_fraktguiden_packages'] ) ) {
        $order->checkout_update_packages( $_COOKIE['_fraktguiden_packages'] );
        setcookie( '_fraktguiden_packages', '', $expire );
      }

      if ( isset( $_COOKIE['_fraktguiden_pickup_point_id'] ) && isset( $_COOKIE['_fraktguiden_pickup_point_postcode'] ) && isset( $_COOKIE['_fraktguiden_pickup_point_info_cached'] ) ) {
        $order->checkout_update_pickup_point_data(
            $_COOKIE['_fraktguiden_pickup_point_id'],
            $_COOKIE['_fraktguiden_pickup_point_postcode'],
            $_COOKIE['_fraktguiden_pickup_point_info_cached']
        );

        // Unset cookies.
        // This does not work at the moment as headers has already been sent.
        // @todo: Find an earlier hook
        setcookie( '_fraktguiden_pickup_point_id', '', $expire );
        setcookie( '_fraktguiden_pickup_point_postcode', '', $expire );
        setcookie( '_fraktguiden_pickup_point_info_cached', '', $expire );
      }
    }
  }

  /**
   * Updates pickup points from admin/order items.
   *
   * @param $order_id
   * @param $shipping_items
   */
  static function admin_saved_order_items( $order_id, $shipping_items ) {
    $order = new Bring_WC_Order_Adapter( new WC_Order( $order_id ) );
    $order->admin_update_pickup_point( $shipping_items );
  }

  /**
   * HTML for checkout recipient page / emails etc.
   *
   * @param string $content
   * @param WC_Order $wc_order
   * @return string
   */
  static function checkout_order_shipping_to_display_shipped_via( $content, $wc_order ) {
    $shipping_methods = $wc_order->get_shipping_methods();
    foreach ( $shipping_methods as $id => $shipping_method ) {
      if (
        $shipping_method['method_id'] == self::ID . ':servicepakke' &&
        key_exists( 'fraktguiden_pickup_point_info_cached', $shipping_method ) &&
        $shipping_method['fraktguiden_pickup_point_info_cached']
      ) {
        $info    = $shipping_method['fraktguiden_pickup_point_info_cached'];
        $content = $content . '<div class="bring-order-details-pickup-point"><div class="bring-order-details-selected-text">' . self::get_i18n()['PICKUP_POINT'] . ':</div><div class="bring-order-details-info-text">' . str_replace( "|", '<br>', $info ) . '</div></div>';
      }
    }
    return $content;
  }

  /**
   * Text translation strings for ui JavaScript.
   *
   * @return array
   */
  static function get_i18n() {
    return [
        'PICKUP_POINT'               => __( 'Pickup point', 'bring-fraktguiden' ),
        'LOADING_TEXT'               => __( 'Please wait...', 'bring-fraktguiden' ),
        'VALIDATE_SHIPPING1'         => __( 'Fraktguiden requires the following fields', 'bring-fraktguiden' ),
        'VALIDATE_SHIPPING_POSTCODE' => __( 'Valid shipping postcode', 'bring-fraktguiden' ),
        'VALIDATE_SHIPPING_COUNTRY'  => __( 'Valid shipping postcode', 'bring-fraktguiden' ),
        'VALIDATE_SHIPPING2'         => __( 'Please update the fields and save the order first', 'bring-fraktguiden' ),
        'SERVICE_PLACEHOLDER'        => __( 'Please select service', 'bring-fraktguiden' ),
        'POSTCODE'                   => __( 'Postcode', 'bring-fraktguiden' ),
        'PICKUP_POINT_PLACEHOLDER'   => __( 'Please select pickup point', 'bring-fraktguiden' ),
        'SELECTED_TEXT'              => __( 'Selected pickup point', 'bring-fraktguiden' ),
        'PICKUP_POINT_NOT_FOUND'     => __( 'No pickup points found for postcode', 'bring-fraktguiden' ),
        'GET_RATE'                   => __( 'Get Rate', 'bring-fraktguiden' ),
        'PLEASE_WAIT'                => __( 'Please wait', 'bring-fraktguiden' ),
        'SERVICE'                    => __( 'Service', 'bring-fraktguiden' ),
        'RATE_NOT_AVAILABLE'         => __( 'Rate is not available for this order. Please try another service', 'bring-fraktguiden' ),
        'REQUEST_FAILED'             => __( 'Request was not successful', 'bring-fraktguiden' ),
        'ADD_POSTCODE'               => __( 'Please add postal code', 'bring-fraktguiden' ),
    ];
  }

  /**
   * Prints shipping info json
   *
   * Only available from admin
   */
  static function wp_ajax_get_bring_shipping_info_var() {
    header( 'Content-type: application/json' );
    echo json_encode( array(
        'bring_shipping_info' => self::get_bring_shipping_info_for_order()
    ) );
    die();
  }

  /**
   * Prints rate json for a bring service.
   *
   * Only available from admin.
   * @todo: refactor!!
   */
  static function wp_ajax_get_rate() {
    header( 'Content-type: application/json' );

    $result = [
        'success'  => false,
        'rate'     => null,
        'packages' => null,
    ];

    if ( isset( $_GET['post_id'] ) && isset( $_GET['service'] ) ) {

      $order = new WC_Order( $_GET['post_id'] );
      $items = $order->get_items();

      $fake_cart = [ ];
      foreach ( $items as $item ) {
        $fake_cart[uniqid()] = [
            'quantity' => $item['qty'],
            'data'     => new WC_Product_Simple( $item['product_id'] )
        ];
      }

      //include( '../../common/class-fraktguiden-packer.php' );
      $packer = new Fraktguiden_Packer();

      $product_boxes = $packer->create_boxes( $fake_cart );

      $packer->pack( $product_boxes, true );

      $package_params = $packer->create_packages_params();

      //@todo: share / filter
      $standard_params = array(
          'clientUrl'           => $_SERVER['HTTP_HOST'],
          'from'                => Fraktguiden_Helper::get_option( 'from_zip' ),
          'fromCountry'         => Fraktguiden_Helper::get_option( 'from_country' ),
          'to'                  => $order->shipping_postcode,
          'toCountry'           => $order->shipping_country,
          'postingAtPostOffice' => ( Fraktguiden_Helper::get_option( 'post_office' ) == 'no' ) ? 'false' : 'true',
          'additional'          => ( Fraktguiden_Helper::get_option( 'evarsling' ) == 'yes' ) ? 'evarsling' : '',
      );
      $params          = array_merge( $standard_params, $package_params );

      $url = add_query_arg( $params, WC_Shipping_Method_Bring::SERVICE_URL );

      $url .= '&product=' . $_GET['service'];

      // Make the request.
      $request  = new WP_Bring_Request();
      $response = $request->get( $url );

      if ( $response->status_code == 200 ) {

        $json = json_decode( $response->get_body(), true );

        $service = $json['Product']['Price']['PackagePriceWithoutAdditionalServices'];
        $rate    = Fraktguiden_Helper::get_option( 'vat' ) == 'exclude' ? $service['AmountWithoutVAT'] : $service['AmountWithVAT'];

        $result['success']  = true;
        $result['rate']     = $rate;
        $result['packages'] = json_encode( $package_params );
      }
    }

    echo json_encode( $result );

    die();
  }

  static function get_pickup_points( $country, $postcode ) {
    $request = new WP_Bring_Request();
    return $request->get( self::BASE_URL . '/' . $country . '/postalCode/' . $postcode . '.json' );
  }
  /**
   * Prints pickup points json
   */
  static function wp_ajax_get_pickup_points() {
    if ( isset( $_GET['country'] ) && $_GET['postcode'] ) {
      $response = self::get_pickup_points( $_GET['country'], $_GET['postcode'] );

      header( "Content-type: application/json" );
      status_header( $response->status_code );

      if ( $response->status_code != 200 ) {
        echo '{}';
      }
      else {
        echo $response->get_body();
      }
    }
    die();
  }

  /**
   * Hide shipping meta data from order items (WooCommerce 2.6)
   * https://github.com/woothemes/woocommerce/issues/9094
   *
   * @param array $hidden_items
   * @return array
   */
  static function woocommerce_hidden_order_itemmeta( $hidden_items ) {
    $hidden_items[] = '_fraktguiden_pickup_point_id';
    $hidden_items[] = '_fraktguiden_pickup_point_postcode';
    $hidden_items[] = '_fraktguiden_pickup_point_info_cached';

    return $hidden_items;
  }

}