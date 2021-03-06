<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

if ( ! class_exists( 'WP_Bring_Request' ) ) {
  include_once 'common/http/class-wp-bring-request.php';
}
if ( ! class_exists( 'Fraktguiden_Helper' ) ) {
  include_once 'common/class-fraktguiden-helper.php';
}

if ( ! class_exists( 'Fraktguiden_Packer' ) ) {
  include_once( 'common/class-fraktguiden-packer.php' );
}

/**
 * Bring class for calculating and adding rates.
 *
 * License: See license.txt
 *
 * @category    Shipping Method
 * @author      Driv Digital
 * @package     Woocommerce
 */
class WC_Shipping_Method_Bring extends WC_Shipping_Method {

  const SERVICE_URL = 'https://api.bring.com/shippingguide/products/all.json';

  const ID = Fraktguiden_Helper::ID;

  const DEFAULT_MAX_PRODUCTS = 100;

  const DEFAULT_ALT_FLAT_RATE = 200;

  private $from_country = '';
  private $from_zip = '';
  private $post_office = '';
  private $vat = '';
  private $evarsling = '';
  protected $services = array();
  protected $service_name = '';
  private $display_desc = '';
  private $max_products = '';
  private $alt_flat_rate = '';

  private $debug = '';

  /** @var WC_Logger */
  private $log;

  /** @var array */
  protected $packages_params = [ ];

  /**
   * @constructor
   */
  public function __construct() {
    $this->id           = self::ID;
    $this->method_title = __( 'Bring Fraktguiden', 'bring-fraktguiden' );

    // Load the form fields.
    $this->init_form_fields();

    // Load the settings.
    $this->init_settings();

    // Debug configuration
    $this->debug = $this->settings['debug'];
    $this->log   = new WC_Logger();

    // Define user set variables

    // WC_Shipping_Method
    $this->enabled      = $this->settings['enabled'];
    $this->title        = $this->settings['title'];
    $this->availability = $this->settings['availability'];
    $this->countries    = $this->settings['countries'];
    $this->fee          = $this->settings['handling_fee'];

    // WC_Shipping_Method_Bring
    $this->from_country = array_key_exists( 'from_country', $this->settings ) ? $this->settings['from_country'] : '';
    $this->from_zip     = array_key_exists( 'from_zip', $this->settings ) ? $this->settings['from_zip'] : '';
    $this->post_office  = array_key_exists( 'post_office', $this->settings ) ? $this->settings['post_office'] : '';
    $this->vat          = array_key_exists( 'vat', $this->settings ) ? $this->settings['vat'] : '';
    $this->evarsling    = array_key_exists( 'evarsling', $this->settings ) ? $this->settings['evarsling'] : '';
    $this->services     = array_key_exists( 'services', $this->settings ) ? $this->settings['services'] : '';
    $this->service_name = array_key_exists( 'service_name', $this->settings ) ? $this->settings['service_name'] : 'DisplayName';
    $this->display_desc = array_key_exists( 'display_desc', $this->settings ) ? $this->settings['display_desc'] : 'no';
    $this->max_products = ! empty( $this->settings['max_products'] ) ? (int)$this->settings['max_products'] : self::DEFAULT_MAX_PRODUCTS;
    // Extra safety, in case shop owner blanks ('') the value.
    if ( ! empty( $this->settings['alt_flat_rate'] ) ) {
      $this->alt_flat_rate = (int)$this->settings['alt_flat_rate'];
    }
    elseif ( empty( $this->settings['alt_flat_rate'] ) ) {
      $this->alt_flat_rate = '';
    }
    else {
      $this->alt_flat_rate = self::DEFAULT_ALT_FLAT_RATE;
    }

    // The packer may make a lot of recursion when the cart contains many items.
    // Make sure xdebug max_nesting_level is raised.
    // See: http://stackoverflow.com/questions/4293775/increasing-nesting-functions-calls-limit
    ini_set( 'xdebug.max_nesting_level', 10000 );

    add_action( 'woocommerce_update_options_shipping_' . $this->id, array( &$this, 'process_admin_options' ) );

    if ( ! $this->is_valid_for_use() ) {
      $this->enabled = false;
    }
  }

  /**
   * Returns true if the required options are set
   *
   * @return boolean
   */
  public function is_valid_for_use() {
    $dimensions_unit = get_option( 'woocommerce_dimension_unit' );
    $weight_unit     = get_option( 'woocommerce_weight_unit' );
    $currency        = get_option( 'woocommerce_currency' );
    return $weight_unit && $dimensions_unit && $currency;
  }

  /**
   * Default settings.
   *
   * @return void
   */
  public function init_form_fields() {
    global $woocommerce;
    $services = Fraktguiden_Helper::get_all_services();

    // @todo
    $wc_log_dir = '';
    if ( defined( 'WC_LOG_DIR' ) ) {
      $wc_log_dir = WC_LOG_DIR;
    }

    $this->form_fields = [
        'general_options_title' => [
            'type'  => 'title',
            'title' => __( 'Shipping Options', 'bring-fraktguiden' ),
        ],
        'enabled'               => array(
            'title'   => __( 'Enable', 'bring-fraktguiden' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Bring Fraktguiden', 'bring-fraktguiden' ),
            'default' => 'no'
        ),
        'title'                 => array(
            'title'    => __( 'Title', 'bring-fraktguiden' ),
            'type'     => 'text',
            'desc_tip' => __( 'This controls the title which the user sees during checkout.', 'bring-fraktguiden' ),
            'default'  => __( 'Bring Fraktguiden', 'bring-fraktguiden' )
        ),
        'handling_fee'          => array(
            'title'    => __( 'Delivery Fee', 'bring-fraktguiden' ),
            'type'     => 'text',
            'desc_tip' => __( 'What fee do you want to charge for Bring, disregarded if you choose free. Leave blank to disable.', 'bring-fraktguiden' ),
            'default'  => ''
        ),
        'post_office'           => array(
            'title'    => __( 'Post office', 'bring-fraktguiden' ),
            'type'     => 'checkbox',
            'label'    => __( 'Shipping from post office', 'bring-fraktguiden' ),
            'desc_tip' => __( 'Flag that tells whether the parcel is delivered at a post office when it is shipped.', 'bring-fraktguiden' ),
            'default'  => 'no'
        ),
        'from_zip'              => array(
            'title'    => __( 'From zip', 'bring-fraktguiden' ),
            'type'     => 'text',
            'desc_tip' => __( 'This is the zip code of where you deliver from. For example, the post office.', 'bring-fraktguiden' ),
            'default'  => ''
        ),
        'from_country'          => array(
            'title'    => __( 'From country', 'bring-fraktguiden' ),
            'type'     => 'select',
            'desc_tip' => __( 'This is the country of origin where you deliver from (If omitted WooCommerce\'s default location will be used. See WooCommerce - Settings - General)', 'bring-fraktguiden' ),
            'class'    => 'chosen_select',
            'css'      => 'width: 450px;',
            'default'  => $woocommerce->countries->get_base_country(),
            'options'  => Fraktguiden_Helper::get_nordic_countries()
        ),
        'vat'                   => array(
            'title'    => __( 'Display price', 'bring-fraktguiden' ),
            'type'     => 'select',
            'desc_tip' => __( 'How to calculate delivery charges', 'bring-fraktguiden' ),
            'default'  => 'include',
            'options'  => array(
                'include' => __( 'VAT included', 'bring-fraktguiden' ),
                'exclude' => __( 'VAT excluded', 'bring-fraktguiden' )
            ),
        ),
        'evarsling'             => array(
            'title'    => __( 'Recipient notification', 'bring-fraktguiden' ),
            'type'     => 'checkbox',
            'label'    => __( 'Recipient notification over SMS or E-Mail', 'bring-fraktguiden' ),
            'desc_tip' => __( 'If not checked, Fraktguiden will add a fee for paper based recipient notification.<br/>If checked, the recipient will receive notification over SMS or E-mail when the parcel has arrived.<br/>Applies to Bedriftspakke, Kliman&oslash;ytral Servicepakke and Bedriftspakke Ekspress-Over natten 09', 'bring-fraktguiden' ),
            'default'  => 'no'
        ),
        'availability'          => array(
            'title'   => __( 'Method availability', 'bring-fraktguiden' ),
            'type'    => 'select',
            'default' => 'all',
            'class'   => 'availability',
            'options' => array(
                'all'      => __( 'All allowed countries', 'bring-fraktguiden' ),
                'specific' => __( 'Specific Countries', 'bring-fraktguiden' )
            )
        ),
        'countries'             => array(
            'title'   => __( 'Specific Countries', 'bring-fraktguiden' ),
            'type'    => 'multiselect',
            'class'   => 'chosen_select',
            'css'     => 'width: 450px;',
            'default' => '',
            'options' => $woocommerce->countries->countries
        ),
        'services'              => array(
            'title'   => __( 'Services', 'bring-fraktguiden' ),
            'type'    => 'multiselect',
            'class'   => 'chosen_select',
            'css'     => 'width: 450px;',
            'default' => '',
            'options' => $services
        ),

        'service_name' => array(
            'title'    => __( 'Display Service As', 'bring-fraktguiden' ),
            'type'     => 'select',
            'desc_tip' => __( 'The service name displayed to the customer', 'bring-fraktguiden' ),
            'default'  => 'DisplayName',
            'options'  => array(
                'DisplayName' => __( 'Display Name', 'bring-fraktguiden' ),
                'ProductName' => __( 'Product Name', 'bring-fraktguiden' ),
            )
        ),

        'display_desc'  => array(
            'title'    => __( 'Display Description', 'bring-fraktguiden' ),
            'type'     => 'checkbox',
            'label'    => __( 'Add description after the service', 'bring-fraktguiden' ),
            'desc_tip' => __( 'Show service description after the name of the service', 'bring-fraktguiden' ),
            'default'  => 'no'
        ),
        'max_products'  => array(
            'title'    => __( 'Max products', 'bring-fraktguiden' ),
            'type'     => 'text',
            'desc_tip' => __( 'Maximum of products in the cart before offering a flat rate', 'bring-fraktguiden' ),
            'default'  => self::DEFAULT_MAX_PRODUCTS
        ),
        'alt_flat_rate' => array(
            'title'    => __( 'Flat rate', 'bring-fraktguiden' ),
            'type'     => 'text',
            'desc_tip' => __( 'Offer a flat rate if the cart reaches max products or a product in the cart does not have the required dimensions', 'bring-fraktguiden' ),
            'default'  => self::DEFAULT_ALT_FLAT_RATE
        ),
        'debug'         => array(
            'title'       => __( 'Debug', 'bring-fraktguiden' ),
            'type'        => 'checkbox',
            'label'       => __( 'Enable debug logs', 'bring-fraktguiden' ),
            'description' => __( 'These logs will be saved in', 'bring-fraktguiden' ) . ' <code>' . $wc_log_dir . '</code>',
            'default'     => 'no'
        )
    ];

  }

  /**
   * Display settings in HTML.
   *
   * @return void
   */
  public function admin_options() {
    global $woocommerce; ?>

    <h3><?php echo $this->method_title; ?></h3>
    <p><?php _e( 'Bring Fraktguiden is a shipping method using Bring.com to calculate rates.', 'bring-fraktguiden' ); ?></p>
    <p>
      <a href="<?php echo admin_url(); ?>admin-ajax.php?action=bring_system_info"
         target="_blank"><?php echo __( 'View system info', 'bring-fraktguiden' ) ?></a>
    </p>

    <table class="form-table">

      <?php if ( $this->is_valid_for_use() ) :
        $this->generate_settings_html();
      else : ?>
        <div class="inline error"><p>
            <strong><?php _e( 'Gateway Disabled', 'bring-fraktguiden' ); ?></strong>
            <br/> <?php printf( __( 'Bring shipping method requires <strong>weight &amp; dimensions</strong> to be enabled. Please enable them on the <a href="%s">Catalog tab</a>. <br/> In addition, Bring also requires the <strong>Norweigian Krone</strong> currency. Choose that from the <a href="%s">General tab</a>', 'bring-fraktguiden' ), 'admin.php?page=woocommerce_settings&tab=catalog', 'admin.php?page=woocommerce_settings&tab=general' ); ?>
          </p></div>
      <?php endif; ?>

    </table> <?php
  }

  public function validate_services_table_field( $key, $value ) {
    return isset( $value ) ? $value : array();
  }

  public function process_admin_options() {
    parent::process_admin_options();

    // Process services table
    $services_field               = $this->get_field_key( 'services2' );
    $services_custom_prices_field = $services_field . '_custom_prices';
    $custom_prices                = [ ];
    if ( isset( $_POST[$services_field] ) ) {
      $checked_services = $_POST[$services_field];
      foreach ( $checked_services as $key => $service ) {

        if ( isset( $_POST[$services_custom_prices_field][$service] ) ) {
          $custom_prices[$service] = $_POST[$services_custom_prices_field][$service];
        }
      }
    }

    update_option( $services_custom_prices_field, $custom_prices );
  }

  public function generate_services_table_html() {
    $services      = Fraktguiden_Helper::get_services_data();
    $selected      = $this->services2;
    $field_key     = $this->get_field_key( 'services2' );
    $custom_prices = get_option( $field_key . '_custom_prices' );

    ob_start();
    ?>

    <tr valign="top">
      <th scope="row" class="titledesc">
        <label
            for="<?php echo $field_key ?>"><?php _e( 'Services 2', self::TEXT_DOMAIN ); ?></label>
      </th>
      <td class="forminp">
        <table class="wc_shipping widefat fraktguiden-services-table">
          <thead>
          <tr>
            <th class="fraktguiden-services-table-col-enabled">Enabled</th>
            <th class="fraktguiden-services-table-col-service">Service</th>
            <th class="fraktguiden-services-table-col-custom-price">Egendefinert pris</th>
          </tr>
          </thead>
          <tbody>

          <?php
          foreach ( $services as $key => $service ) {
            $id               = $field_key . '_' . $key;
            $prices_field_key = $field_key . '_custom_prices[' . $key . ']';
            $custom_price     = isset( $custom_prices[$key] ) ? $custom_prices[$key] : '';
            $checked          = in_array( $key, $selected );
            ?>
            <tr>
              <td class="fraktguiden-services-table-col-enabled">
                <label for="<?php echo $id; ?>"
                       style="display:inline-block; width: 100%">
                  <input type="checkbox" id="<?php echo $id; ?>"
                         name="<?php echo $field_key; ?>[]"
                         value="<?php echo $key; ?>" <?php echo( $checked ? 'checked' : '' ); ?> />
                </label>
              </td>
              <td class="fraktguiden-services-table-col-name">
                <span data-tip="<?php echo $service['HelpText']; ?>"
                      class="woocommerce-help-tip"></span>
                <label class="fraktguiden-service" for="<?php echo $id; ?>"
                       data-ProductName="<?php echo $service['ProductName']; ?>"
                       data-DisplayName="<?php echo $service['DisplayName']; ?>">
                  <?php echo $service[$this->service_name]; ?>
                </label>
              </td>
              <td class="fraktguiden-services-table-col-custom-price">
                <input type="text" name="<?php echo $prices_field_key; ?>"
                       value="<?php echo $custom_price; ?>"/>
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

  public function pack_order( $cart ) {

      $packer = new Fraktguiden_Packer();

      $product_boxes = $packer->create_boxes( $cart );
//      // Create an array of 'product boxes' (l,w,h,weight).
//      $product_boxes = array();
//
//      /** @var WC_Cart $cart */
//      $cart = $woocommerce->cart;
//      foreach ( $cart->get_cart() as $values ) {
//
//        /** @var WC_Product $product */
//        $product = $values['data'];
//
//        if ( ! $product->needs_shipping() ) {
//          continue;
//        }
//        $quantity = $values['quantity'];
//        for ( $i = 0; $i < $quantity; $i++ ) {
//          if ( ! $product->has_dimensions() ) {
//            // If the product has no dimensions, assume the lowest unit 1x1x1 cm
//            $dims = array( 0, 0, 0 );
//          }
//          else {
//            $dims = array(
//                $product->length,
//                $product->width,
//                $product->height
//            );
//          }
//
//          // Workaround weird LAFFPack issue where the dimensions are expected in reverse order.
//          rsort( $dims );
//
//          $box = array(
//              'length'          => $dims[0],
//              'width'           => $dims[1],
//              'height'          => $dims[2],
//              'weight'          => $product->weight,
//              'weight_in_grams' => $packer->get_weight( $product->weight ) // For $packer->exceeds_max_package_values only.
//          );
//
//          // Return if product is larger than available Bring packages.
//          if ( $packer->exceeds_max_package_values( $box ) ) {
//            return;
//          }
//
//          $product_boxes[] = $box;
//        }
//      }

      if ( ! $product_boxes ) {
        return false;
      }

      // Pack product boxes.
      $packer->pack( $product_boxes, true );

      // Create the url.
      return $packer->create_packages_params();
  }
  /**
   * Calculate shipping costs.
   *
   * @todo: in 2.6, the package param was added. Investigate this!
   */
  public function calculate_shipping( $package = array() ) {
    global $woocommerce;

    //include_once( 'common/class-fraktguiden-packer.php' );

    // Offer flat rate if the cart contents exceeds max product.
    if ( $woocommerce->cart->get_cart_contents_count() > $this->max_products ) {
      if ( $this->alt_flat_rate == '' ) {
        return;
      }
      $rate = array(
          'id'    => $this->id . ':' . 'alt_flat_rate',
          'cost'  => $this->alt_flat_rate,
          'label' => $this->method_title . ' flat rate',
      );
      $this->add_rate( $rate );
    }
    else {
      $cart = $woocommerce->cart->get_cart();
      $this->packages_params = $this->pack_order( $cart );
      if ( ! $this->packages_params ) {
        return;
      }

      if ( is_checkout() ) {
        $_COOKIE['_fraktguiden_packages'] = json_encode( $this->packages_params );
      }

      // Request parameters.
      $params = array_merge( $this->create_standard_url_params(), $this->packages_params );
      // Remove any empty elements.
      $params = array_filter( $params );

      $url = add_query_arg( $params, self::SERVICE_URL );

      // Add all the selected services to the URL
      $service_count = 0;
      if ( $this->services && count( $this->services ) > 0 ) {
        foreach ( $this->services as $service ) {

          $url .= '&product=' . $service;
        }
      }

      // Make the request.
      $request  = new WP_Bring_Request();
      $response = $request->get( $url );

      if ( $response->status_code != 200 ) {
        return;
      }

      // Decode the JSON data from bring.
      $json = json_decode( $response->get_body(), true );
      // Filter the response json to get only the selected services from the settings.
      $rates = $this->get_services_from_response( $json );
      $rates = apply_filters( 'bring_shipping_rates', $rates );

      if ( $this->debug != 'no' ) {
        $this->log->add( $this->id, 'params: ' . print_r( $params, true ) );

        if ( $rates ) {
          $this->log->add( $this->id, 'Rates found: ' . print_r( $rates, true ) );
        }
        else {
          $this->log->add( $this->id, 'No rates found for params: ' . print_r( $params, true ) );
        }

        $this->log->add( $this->id, 'Request url: ' . print_r( $url, true ) );
      }

      // Calculate rate.
      if ( $rates ) {
        foreach ( $rates as $rate ) {
          $this->add_rate( $rate );
        }
      }
    }
  }

  /**
   * @param array $response The JSON response from Bring.
   * @return array|boolean
   */
  private function get_services_from_response( $response ) {
    if ( ! $response || ( is_array( $response ) && count( $response ) == 0 ) || empty( $response['Product'] ) ) {
      return false;
    }

    $rates = array();

    // Fix for when only one service is found. It's not returned in an array :/
    if ( empty( $response['Product'][0] ) ) {
      $cache = $response['Product'];
      unset( $response['Product'] );
      $response['Product'][] = $cache;
    }

    foreach ( $response['Product'] as $serviceDetails ) {
      if ( ! empty( $this->services ) && ! in_array( $serviceDetails['ProductId'], $this->services ) ) {
        continue;
      }

      $service = $serviceDetails['Price']['PackagePriceWithoutAdditionalServices'];
      $rate    = $this->vat == 'exclude' ? $service['AmountWithoutVAT'] : $service['AmountWithVAT'];

      $rate = array(
          'id'    => $this->id . ':' . sanitize_title( $serviceDetails['ProductId'] ),
          'cost'  => (float)$rate + (float)$this->fee,
          'label' => $serviceDetails['GuiInformation'][$this->service_name]
              . ( $this->display_desc == 'no' ?
                  '' : ': ' . $serviceDetails['GuiInformation']['DescriptionText'] ),
      );

      array_push( $rates, $rate );
    }
    return $rates;
  }

  /**
   * Standard url params for the Bring http request.
   *
   * @return array
   */
  public function create_standard_url_params() {
    global $woocommerce;
    return apply_filters( 'bring_fraktguiden_standard_url_params', array(
        'clientUrl'           => $_SERVER['HTTP_HOST'],
        'from'                => $this->from_zip,
        'fromCountry'         => $this->get_selected_from_country(),
        'to'                  => $woocommerce->customer->get_shipping_postcode(),
        'toCountry'           => $woocommerce->customer->get_shipping_country(),
        'postingAtPostOffice' => ( $this->post_office == 'no' ) ? 'false' : 'true',
        'additional'          => ( $this->evarsling == 'yes' ) ? 'evarsling' : '',
        'language'            => $this->get_bring_language()
    ) );
  }

  public function get_bring_language() {
    $language = substr(get_bloginfo ( 'language' ), 0, 2);

    $languages = [
        'dk' => 'da',
        'fi' => 'fi',
        'nb' => 'no',
        'nn' => 'no',
        'sv' => 'se'
    ];

    return array_key_exists($language, $languages) ? $languages[$language] : 'en';
  }

  public function get_selected_from_country() {
    global $woocommerce;
    return isset( $this->from_country ) ?
        $this->from_country : $woocommerce->countries->get_base_country();
  }

}

