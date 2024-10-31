<?php

/*
 * Plugin Name: Product Catalog Plugin for WooCommerce
 * Description: Price products using nFusion Solutions Product Catalog
 * Developer: nFusion Solutions
 * Author URI: https://nfusionsolutions.com
 * WC requires at least: 3.0.0
 * WC tested up to: 8.3.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 
 * Version: 3.0.8
*/

// Declare our plugin compatible with WC new High-Performance Order Storage (HPOS)
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
});

require_once 'includes/model/transient.php';

define("NFS_CATALOG_PLUGIN_VERSION",   "3.0.8");
define("NFS_CATALOG_REMOTE_TIMEOUT_SECONDS", 2);
define("NFS_CATALOG_DEFAULT_REINDEXING_INTERVAL_MINUTES", 10);
define("NFS_CATALOG_DEFAULT_PRICE_UPDATE_INTERVAL_SECONDS", 60);
define("FEE_EXEMPT_METHODS", array("cod", "cheque", "bacs",
		"stripe_ach", "stripe_bacs_debit", "stripe_acss_debit",
		"stripe_au_becs_debit", "stripe_us_bank_account", "stripe_oxxo",
		"stripe_konbini", "stripe_customer_balance", "coinbase_commerce_gateway"));

function nfs_catalog_plugin_tryGetProduct($skus){
	$productsMap = nfs_catalog_plugin_get_all_products();
	if($productsMap !== false) {
		foreach($skus as $sku) {
			if(array_key_exists($sku, $productsMap)){
				$product = $productsMap[$sku];
				if(isset($product)) {
					return $product;
				}
			}
		}
	}
	return false;
}

function nfs_catalog_plugin_get_all_products(){
    $currency = get_woocommerce_currency();
    $ttlSeconds = get_option('nfusion_price_update_interval'); //cache timeout in seconds
    $ttlSecondarySeconds = 3600; //very long timeout for secondary cache
    $productsMapKey = 'nfs_catalog_products_all_' . $currency;
    $productsMapSecondaryKey = 'nfs_catalog_products_all_secondary_' . $currency;
    //first check cache
    $productsMap = get_transient($productsMapKey);
    if ($productsMap === false || !isset($productsMap)) {
        //not found in cache, fetch from remote
        $productsMap = nfs_catalog_plugin_fetch_and_cache_products($currency, $productsMapKey, $ttlSeconds, $productsMapSecondaryKey, $ttlSecondarySeconds);
    } else {
        //item found in cache, but we need to double-check that it is not too old and stuck in cache
        //we are using a timestamp as a backup for intermittent behavior or WP transients
        //that seems to cause them to convert to infinite expiration on their own
        if (!isset($productsMap['timestamp']) || (time() > ($productsMap['timestamp'] + $ttlSeconds))) {
            $productsMap = nfs_catalog_plugin_fetch_and_cache_products($currency, $productsMapKey, $ttlSeconds, $productsMapSecondaryKey, $ttlSecondarySeconds);
        }
    }

    //if we don't have product data at this point, grab from secondary
    if ($productsMap === false || !isset($productsMap)) {
        $productsMap = get_transient($productsMapSecondaryKey);
    }

    return $productsMap;
}

function nfs_catalog_plugin_fetch_and_cache_products($currency, $productsMapKey, $ttlSeconds, $productsMapSecondaryKey, $ttlSecondarySeconds){
    //we want to prevent many sessions from trying to make the remote call at the same time
    //this can happen around cache expiry boundaries. The problem can become more pronounced if the remote
    //server is slow to response, since the remote calls are blocking. An asynchronous approach with a true semaphore
    //might be preferred here, but options are limited in WordPress/PHP stack without plugins (whose existence we cannot guarantee)

    //here we will use a second transient as a sort of pseudo-semaphore. It will not truly prevent duplicate requests, but it may reduce them preventing stampede conditions.
    $semaphoreKey = 'nfs_catalog_request_semaphore_' . $currency;
    $semaphoreInUse = get_transient($semaphoreKey);
    if ($semaphoreInUse === false || !isset($semaphoreInUse)) {
        try {
            //set the semaphore transient to block other requests
            set_transient($semaphoreKey, 1, NFS_CATALOG_REMOTE_TIMEOUT_SECONDS); //set transient ttl same as remote request timeout
            $remoteResult = nfs_catalog_plugin_fetch_products_from_remote($currency);
            if ($remoteResult !== false) { //only cache if we got a valid response
                //store new data in first and second level cache
                $productsMap = $remoteResult;
                set_transient($productsMapKey, $productsMap, $ttlSeconds);
                set_transient($productsMapSecondaryKey, $productsMap, $ttlSecondarySeconds);

                return $productsMap;
            }
        } catch (Exception $ex) {
            error_log('Error fetching product data: ' . $ex->getMessage());
        } finally {
            //delete the semaphore transient
            delete_transient($semaphoreKey);
        }
    }

    //if the fetch fails or semaphore in use, return false
    return false;
}

// Schedule an action with the hook 'nfs_catalog_plugin_product_reindexing' to run on an interval
function schedule_product_reindexing() {
	if ( is_plugin_active('action-scheduler/action-scheduler.php' )) {
		if ( get_option('nfusion_enable_product_reindexing') == 'yes' ) {
			$reindexInterval = get_option('nfusion_product_reindexing_interval');
			if(empty($reindexInterval)) {
				$reindexInterval = NFS_CATALOG_DEFAULT_REINDEXING_INTERVAL_MINUTES;
			}

			if ( false === as_has_scheduled_action( 'nfs_catalog_plugin_product_reindexing' ) ) {
				as_schedule_recurring_action( time(), $reindexInterval * MINUTE_IN_SECONDS, 'nfs_catalog_plugin_product_reindexing', array(), '', true );
			}
		} else {
			if ( as_has_scheduled_action('nfs_catalog_plugin_product_reindexing') ) {
				as_unschedule_all_actions( 'nfs_catalog_plugin_product_reindexing' );
			}
		}
	}
}
add_action( 'init', 'schedule_product_reindexing' );

/**
 * A callback to run when the 'nfs_catalog_plugin_product_reindexing' scheduled action is run.
 *
 * Sets the WooCommerce price field to the current
 * nFusion price obtained from the product catalog.
 */
function nfs_catalog_plugin_reindex_products() {
	$page = 1;
    $limit = 500;

    do {
        $args = array(
            'status' => array('draft', 'pending', 'private', 'publish'),
            'limit'  => $limit,
            'page'   => $page
        );

        $products = wc_get_products($args);

        if ($products) {
            foreach ($products as $product) {
                if ($product->is_type('variable')) {
                    $variations = $product->get_available_variations();
                    $variation_skus = wp_list_pluck($variations, 'sku');

                    foreach ($variation_skus as $sku) {
                        $nfsProduct = nfs_catalog_plugin_tryGetProduct(array($sku));
                        if (false !== $nfsProduct) {
                            $variation_id = wc_get_product_id_by_sku($sku);
                            $variation = wc_get_product_object('variation', $variation_id);

                            nfs_catalog_plugin_update_wc_product_price($nfsProduct, $variation);
                        }
                    }
                } else {
                    $nfs_sku = $product->get_meta('nfs_catalog_plugin_sku', true);
                    $wc_sku = $product->get_sku();
                    $nfsProduct = nfs_catalog_plugin_tryGetProduct(array($nfs_sku, $wc_sku));
                    if (false !== $nfsProduct) {
                        nfs_catalog_plugin_update_wc_product_price($nfsProduct, $product);
                    }
                }
            }
        }

        $page++;
    } while (count($products) === $limit); // Continue until fewer products are returned than the limit
}
add_action( 'nfs_catalog_plugin_product_reindexing', 'nfs_catalog_plugin_reindex_products' );

function nfs_catalog_plugin_update_wc_product_price($nfsProduct, $product){
	$ask = nfs_catalog_plugin_getLowestPrice($nfsProduct);
	if( false !== $ask ) {
		$product->set_price( (string) $ask );
		$product->set_regular_price( (string) $ask );
		$product->save();
	}
}

function nfs_catalog_plugin_fetch_products_from_remote($currency){
	if( !class_exists( 'WP_Http' ) ){
		include_once( ABSPATH . WPINC. '/class-http.php' );
	}

	$tenantAlias = get_option('nfusion_tenant_alias');
	$salesChannel = get_option('nfusion_sales_channel');
	$token = get_option('nfusion_api_token');
	$catalogUrl = 'https://'.$tenantAlias.'.nfusioncatalog.com/service/price/pricesbychannel?currency='.$currency.'&channel='.$salesChannel.'&withretailtiers=true&token='.$token;

	$args = array(
		'timeout' => NFS_CATALOG_REMOTE_TIMEOUT_SECONDS,//timeout in seconds
		'headers' => array(
			'User-Agent' => 'wpwc-'.NFS_CATALOG_PLUGIN_VERSION,
			'Accept' => 'application/json',
			'Accept-Encoding' => 'gzip'
		)
	);
	$request = wp_remote_get( $catalogUrl, $args);
	if( is_wp_error( $request ) ) {
		return false;
	}

	$body = wp_remote_retrieve_body( $request );
	$jsonData = json_decode($body, true);
	if( isset($jsonData) && isset($jsonData[0]))
	{
		$productsMap = array();
		foreach ($jsonData as $item) {
			$productsMap[$item['SKU']] = $item;
		}

		if(count($productsMap) !== 0)
		{//we added at least one product
			$productsMap['timestamp'] = time();
			return $productsMap;
		}
	}
	return false;
}

/**
 *
 * Add a custom meta box to the product page
 */
function nfs_catalog_plugin_add_box() {
	add_meta_box(
		'nfs_catalog_plugin_sectionid',
		'nFusion Solutions Catalog Integration',
		'nfs_catalog_plugin_inner_custom_box',
		'product',
		'advanced'
	);
}
add_action( 'admin_head', 'nfs_catalog_plugin_add_box' );

function nfs_catalog_plugin_inner_custom_box() {
	global $post;
	$product = wc_get_product($post->ID);
	$nfs_sku = $product->get_meta('nfs_catalog_plugin_sku');
	echo '<div><label for="nfs_catalog_plugin_sku">Product SKU</label><br/>';
	echo '<input type="text" id="nfs_catalog_plugin_sku" name="nfs_catalog_plugin_sku" value="'.$nfs_sku.'" size="25" /></div>';
}

/**
 * Processes the custom options when a post is saved
 */
function nfs_catalog_plugin_save_product($post_id) {
	$product = wc_get_product($post_id);
	$product->update_meta_data('nfs_catalog_plugin_sku', sanitize_text_field($_POST['nfs_catalog_plugin_sku']));
	$product->save();
}
add_action('woocommerce_process_product_meta', 'nfs_catalog_plugin_save_product', 10, 2);

/**
 * Override the product price from woocommerce with a price from the nfusion catalog
 */
add_filter( 'woocommerce_product_get_price', 'nfs_catalog_plugin_price', 10000, 2 );
add_filter('woocommerce_product_variation_get_price', 'nfs_catalog_plugin_price', 10000, 2);
function nfs_catalog_plugin_price( $price, $product ){
	$nfs_sku = $product->get_meta('nfs_catalog_plugin_sku');
	$wc_sku = $product->get_sku();
	$nfsProduct = nfs_catalog_plugin_tryGetProduct(array($nfs_sku, $wc_sku));

	$quantity = 1;

	if( is_cart() || is_checkout() || ( defined('DOING_AJAX') && DOING_AJAX ) ) {// If Cart/Checkout/Ajax Page
		if( !WC()->cart->is_empty() ) {
			foreach( WC()->cart->get_cart() as $cart_item) {
				$cartItem = $cart_item['data'];
				$cartItemSku = $cartItem->get_sku();
				if($cartItemSku == $product->get_sku()) {
					$quantity = $cart_item['quantity'];
					break;
				}
			}
		}
	}

	if( $nfsProduct !== false ) {
		$ask = round($nfsProduct['Ask'], 2);
		if(isset($nfsProduct['RetailTiers']) && !empty($nfsProduct['RetailTiers'])) {
			$nfsPriceTiers = $nfsProduct['RetailTiers'];
			uasort($nfsPriceTiers, 'nfs_catalog_plugin_compareTiers');//must guarantee tiers are sorted lowest to highest by quantity
			foreach($nfsPriceTiers as $aTier) {
				if($quantity >= $aTier['Quantity']){
					$ask = round($aTier['Ask'], 2);
				}
				else{
					break;//stop searching list, all remaining tiers are larger than quantity
				}
			}
		}
		return $ask;
	}

	return $price;
}

function nfs_catalog_plugin_getLowestPrice($nfsProduct){
	if( $nfsProduct !== false ) {
		$ask = $nfsProduct['Ask'];
		if(isset($nfsProduct['RetailTiers']) && !empty($nfsProduct['RetailTiers'])) {
			$nfsPriceTiers = $nfsProduct['RetailTiers'];
			foreach($nfsPriceTiers as $aTier) {
				if($ask > $aTier['Ask']){
					$ask = $aTier['Ask'];
				}
			}
		}
		return round($ask, 2);
	}

	return false;
}

add_filter( 'woocommerce_get_price_html', 'nfs_catalog_plugin_aslowas_price_html', 100, 2 );
function nfs_catalog_plugin_aslowas_price_html( $priceHtml, $product ){
	if( is_cart() || is_checkout()){
		return $priceHtml;
	}
	$nfs_sku = $product->get_meta('nfs_catalog_plugin_sku');
	$wc_sku = $product->get_sku();
	$nfsProduct = nfs_catalog_plugin_tryGetProduct(array($nfs_sku, $wc_sku));
	if($nfsProduct === false){
		return $priceHtml;
	}

	$lowPriceLabel = get_option('nfusion_low_price_label');
	if(empty($lowPriceLabel)){
		$lowPriceLabel = "as low as";
	}

	return $lowPriceLabel." ".wc_price(nfs_catalog_plugin_getLowestPrice($nfsProduct));
}

add_filter( 'woocommerce_variable_price_html', 'nfs_catalog_plugin_variation_price_html', 100, 2 );
function nfs_catalog_plugin_variation_price_html( $priceHtml, $product ) {
	$variations = $product->get_available_variations();
	$variation_skus = wp_list_pluck( $variations, 'sku' );

	$prices = [];
	foreach($variation_skus as $sku) {
		$nfsProduct = nfs_catalog_plugin_tryGetProduct(array($sku));
		if ($nfsProduct !== false) {
			$prices[$sku] = $nfsProduct['Ask'];
		}
	}

	if(count($prices) === 0) return $priceHtml;

	sort($prices);
	$priceHtml = ( $prices[0] !== $prices[count($prices) - 1] ) ? sprintf( ( '%s - %s'), wc_price( $prices[0] ), wc_price( $prices[count($prices) - 1] )) : wc_price( $prices[0] );

	return $priceHtml;
}

function nfs_catalog_plugin_compareTiers($a, $b) {
	if ($a['Quantity'] == $b['Quantity']) {
		return 0;
	}
	return ($a['Quantity'] < $b['Quantity']) ? -1 : 1;
}

function nfs_catalog_plugin_product_summary_details() {
	global $post;
	$product = wc_get_product( $post );
	if(!$product){
		return '';
	}

	if( $product->is_type( 'variable' ) ) {
		$variations = $product->get_available_variations();
		$variation_skus = wp_list_pluck( $variations, 'sku' );

		foreach($variation_skus as $sku) {
			$nfsProduct = nfs_catalog_plugin_tryGetProduct(array($sku));
			if ($nfsProduct !== false) {
				nfs_catalog_plugin_build_details_html($nfsProduct, true);
			}
		}
	} else {
		$nfs_sku = $product->get_meta('nfs_catalog_plugin_sku');
		$wc_sku = $product->get_sku();
		$nfsProduct = nfs_catalog_plugin_tryGetProduct(array($nfs_sku, $wc_sku));
		if ($nfsProduct !== false) {
			nfs_catalog_plugin_build_details_html($nfsProduct, false);
		} else {
			return '';
		}
	}
}

// Print Tier table after product summary if Multiple Tier exists
add_action('woocommerce_single_product_summary', 'nfs_catalog_plugin_product_summary_details', 21);

function nfs_catalog_plugin_build_details_html($nfsProduct, $isVariableProd) {
	$variableClass = ($isVariableProd) ? " variable-prod " . $nfsProduct['SKU'] : "";
	if($nfsProduct !== false)
	{
		//Product Bid (buy back price)
		$bid = wc_price(round($nfsProduct['Bid'], 2));
		if(get_option('nfusion_show_buy_price') == 'yes'){
			$buyPriceLabel = get_option('nfusion_buy_price_label');
			if(empty($buyPriceLabel)){
				$buyPriceLabel = "We buy at";
			}

			$productBidDiv = "<div class='nfs_catalog_plugin_productbid". $variableClass ."'>".$buyPriceLabel." ".$bid."</div>";
			echo $productBidDiv;
		}

		if(isset($nfsProduct['RetailTiers']) && !empty($nfsProduct['RetailTiers']) )
		{
			$cardPriceLabel = get_option('nfusion_pricing_card_label');
			if(empty($cardPriceLabel)){
				$cardPriceLabel = "Card";
			}

			$checkPriceLabel = get_option('nfusion_pricing_check_label');
			if(empty($checkPriceLabel)){
				$checkPriceLabel = "Check";
			}

			$nfsPriceTiers = $nfsProduct['RetailTiers'];
			if($nfsPriceTiers and get_option('nfusion_show_tiered_pricing') == 'yes') {
				if( is_array( $nfsPriceTiers ) ) {
					//sort the array so we guarantee the order the table is printed
					uasort($nfsPriceTiers, 'nfs_catalog_plugin_compareTiers');
					// After sorting the array, reindex it using array_values()
					$nfsPriceTiers = array_values($nfsPriceTiers);

					$table 	= "<div class='nfs_catalog_plugin_wrapper". $variableClass ."'>";
					$table .= "<h2>Volume Discounts</h2>";
					$table .= "<table class='nfs_catalog_plugin_table' border='1' cellpadding='5'>";
					$table .= "<tr>";
					$table .= "<td>Quantity</td>";
					$table .= "<td>".$checkPriceLabel."</td>";
					if(get_option('nfusion_show_credit_card_price') == 'yes'){
						$table .= "<td>".$cardPriceLabel."</td>";
					}
					$table .= "</tr>";
					$firstRow = true;
					$numTiers = count($nfsPriceTiers);
					for($index = 0; $index < $numTiers; $index++) {
						// Access the current tier
						$currentTier = $nfsPriceTiers[$index];

						// Check if there is a next tier in the array
						if ($index < $numTiers - 1) {
							// Get the next tier
							$nextTier = $nfsPriceTiers[$index + 1];
                            $nextQuantity = $nextTier['Quantity'];
						} else {
							$nextQuantity = null;
						}

						//if the first row has a quantity of greater than 1, then build and artificial first row from the base ask
						if($firstRow){
							if($currentTier['Quantity'] > 1){
								$tempRow = nfs_catalog_plugin_build_tier_row_html(1, $currentTier['Quantity'], $nfsProduct['Ask'],
									get_option('nfusion_show_credit_card_price') == 'yes', get_option('nfusion_cc_price'));
								$table .= $tempRow;
							}
						}

						$tempRow = nfs_catalog_plugin_build_tier_row_html($currentTier['Quantity'], $nextQuantity, $currentTier['Ask'],
							get_option('nfusion_show_credit_card_price') == 'yes', get_option('nfusion_cc_price'));
						$table .= $tempRow;

						$firstRow = false;
					}
					$table .= "</table></div>";

					echo $table;
				} else {
					return $nfsPriceTiers;
				}
			} else {
				return '';
			}
		}
	} else {
		return '';
	}
}

/**
 * Generates HTML for a tier row.
 *
 * @param int     $quantity  The quantity threshold for the tier.
 * @param float   $ask       The retail ask price for the tier.
 * @param bool    $showCC    Whether to show the credit card price.
 * @param float   $ccPercent The percentage adjustment for the credit card price.
 * @return string            The HTML table row containing tier pricing information.
 */
function nfs_catalog_plugin_build_tier_row_html($quantity, $nextQuantity, $ask, $showCC, $ccPercent) {
	$row = "<tr>";

    if($nextQuantity) {
	    $row .= "<td>".$quantity."-".($nextQuantity - 1)."</td>";
    } else {
	    $row .= "<td>".$quantity."+</td>";
    }

	// Append check/retail ask price
	$row .= "<td>".wc_price(round($ask, 2))."</td>";

	// Append CC price
	if($showCC){
		$ccAdjust = ($ccPercent/100) + 1;
		$ccAsk = round($ask * $ccAdjust, 2);
		$row .= "<td>".wc_price($ccAsk)."</td>";
	}
	$row .= "</tr>";
	return $row;
}

add_filter( 'manage_edit-product_columns', 'nfs_catalog_plugin_set_custom_edit_product_columns' );
add_action( 'manage_product_posts_custom_column' , 'nfs_catalog_plugin_custom_product_column', 10, 2 );

function nfs_catalog_plugin_set_custom_edit_product_columns($columns) {
	$columns['nfs_catalog_plugin_sku'] = __( 'NFS SKU' );

	return $columns;
}

function nfs_catalog_plugin_custom_product_column( $column, $post_id ) {
	switch ( $column ) {
		case 'nfs_catalog_plugin_sku' :
			$product = wc_get_product($post_id);
			$nfs_sku = $product->get_meta('nfs_catalog_plugin_sku');
			// $nfs_sku = get_post_meta($post_id, 'nfs_catalog_plugin_sku', true);
			if ( empty($nfs_sku) || strlen($nfs_sku) == 0 )
				'-';
			else
				echo $nfs_sku;
			break;
	}
}

function nfs_catalog_enqueue_frontend() {
	// CSS
	wp_enqueue_style('nfusion-css', plugin_dir_url(__FILE__) . 'includes/css/nfusion.css');
	// JS
	wp_enqueue_script( "nfusion-js", plugin_dir_url( __FILE__ ) . 'includes/js/nfusion.js', array('jquery'), false, true);
	wp_localize_script( 'nfusion-js', 'nfObj', array(
		'ajaxurl' => admin_url( 'admin-ajax.php' )
	));
}
add_action('wp_enqueue_scripts', 'nfs_catalog_enqueue_frontend');

function nfs_catalog_enqueue_backend() {
	/**
	 * Check whether the get_current_screen function exists
	 * because it is loaded only after 'admin_init' hook.
	 */
	if ( function_exists( 'get_current_screen' ) ) {
		$current_screen = get_current_screen();
		if( $current_screen && $current_screen->id === "toplevel_page_nfusion_ppc_settings" ) {
			// Run only on the admin settings page
			wp_enqueue_style('jquery-ui-css', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/themes/base/jquery-ui.min.css');
			wp_enqueue_style('boostrap-css', 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css');

			wp_enqueue_script('jquery-ui-js', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js', array('jquery'));
			wp_enqueue_script('popper-js', 'https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.8/umd/popper.min.js', array('jquery'));
			wp_enqueue_script('bootstrap-js', 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.min.js', array('jquery'));
		}
	}
	wp_enqueue_style('nfusion-admin-css', plugin_dir_url(__FILE__) . 'includes/css/nfusion-admin.css');

	wp_enqueue_script('nfusion-admin-js',plugin_dir_url( __FILE__ ) . 'includes/js/nfusion-admin.js', array('jquery'));
	wp_enqueue_script( "nfusion-js", plugin_dir_url( __FILE__ ) . 'includes/js/nfusion.js', array('jquery'), false, true);
	wp_localize_script( 'nfusion-js', 'nfObj', array(
		'ajaxurl' => admin_url( 'admin-ajax.php' )
	));
}
add_action('admin_enqueue_scripts', 'nfs_catalog_enqueue_backend', 0);

add_action( "wp_ajax_cleartransient", "nf_cache_clear_transient" );
add_action( "wp_ajax_nopriv_cleartransient", "nf_cache_clear_transient" );
function nf_cache_clear_transient(){
	$transients = $_POST['transients'];
	$response = new TransientClearCacheResponse();

	foreach ($transients as $transient) {
		$productsMap = get_transient($transient);
		$age = isset($productsMap['timestamp']) ? secondsToTime($productsMap['timestamp']) : null;
		$cleared = delete_transient($transient);
		$productsMapAfterClear = get_transient($transient);

		$response->addTransient($transient, $productsMap, $age, $cleared, $productsMapAfterClear);
	}

	$JSONResult = json_encode($response);

	// echo result to client
	echo $JSONResult;

	wp_die(); // ajax call must die to avoid trailing 0 in response
}

/**
 * Check if the chosen payment method is not 'cod', 'cheque', or 'bacs'.
 * Returns true if the payment method is not one of the specified methods, otherwise returns false.
 *
 * @param string $chosen_payment_method The chosen payment method ID.
 * @return bool True if the payment method is not 'cod', or 'cheque', otherwise false.
 */
function is_not_fee_exempt($chosen_payment_method) {
	return !in_array($chosen_payment_method, FEE_EXEMPT_METHODS);
}

// Hook into WooCommerce checkout process
add_action('woocommerce_cart_calculate_fees', 'nf_add_card_payment_fee');
function nf_add_card_payment_fee($cart) {
	if (is_admin() && !defined('DOING_AJAX')) return;

	// Check if the cart is not empty
	if ($cart->is_empty()) return;

	if(get_option('nfusion_show_credit_card_price') == 'yes') {
		// Get cc price percent from nFusion settings
		$cc_percent = get_option('nfusion_cc_price');
		if(empty($cc_percent)) return;

		// Guarantee sure we're in checkout and not on a WC endpoints like the order-received page etc.
		if (is_checkout() && !is_wc_endpoint_url()) {
			$chosen_payment_method = WC()->session->get('chosen_payment_method');

			// Check if the payment method is card-based
			if (is_not_fee_exempt($chosen_payment_method)) {
				//we only want to apply the credit card fee to items matched with nfusion catalog
				//this prevents us from adding the cc fee to other items in their shop that they may not want it added to
				//to do this we have to iterate through the cart items and find items that match by SKU
				$running_total = 0;
				foreach ( $cart->get_cart() as $cart_item ) {
				   $cartItem = $cart_item['data'];
				   $cartItemSku = $cartItem->get_sku();
                   $match = nfs_catalog_plugin_tryGetProduct(array($cartItemSku));
				   if(false !== $match){
					   $quantity = $cart_item['quantity'];
					   $subtotal = $cart_item['line_subtotal'];
					   $running_total += $subtotal;
				   }
				}
				
				$percent_fee = $running_total * ($cc_percent / 100);
				$cart->add_fee('Payment Processing Fee', $percent_fee);
			}
		}
	}
}

add_action( 'woocommerce_after_checkout_form', 'nf_refresh_checkout_on_payment_methods_change' );

/**
 * Trigger update when customer changes payment method
 * @return void
 */
function nf_refresh_checkout_on_payment_methods_change(){
	if(get_option('nfusion_show_credit_card_price') == 'yes') {
		wc_enqueue_js( "
          $( 'form.checkout' ).on( 'change', 'input[name^=\'payment_method\']', function() {
             $('body').trigger('update_checkout');
            });
       " );
	}
}

/**
 * Evaluates difference between today's date and the given date
 *
 * @param int $seconds Unix timestamp
 *
 * @return string Time difference (day(s), hour(s), minute(s), second(s))
 */
function secondsToTime($seconds) {
	$dtFrom = new DateTime("@$seconds");
	$currTime = time();
	$dtTo = new DateTime("@$currTime");
	return $dtFrom->diff($dtTo)->format('%a days, %h hours, %i minutes and %s seconds');
}

add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'nfusion_settings_link' );
function nfusion_settings_link( array $links ) {
	$url = get_admin_url() . "?page=nfusion_ppc_settings";
	$settings_link = '<a href="' . $url . '">' . __('Settings', 'textdomain') . '</a>';
	$links[] = $settings_link;
	return $links;
}

/*add global settings start*/
add_action('admin_menu', 'nfusion_catalog_plugin_menu_settings');
function nfusion_catalog_plugin_menu_settings(){
	add_menu_page(
		'nFusion Settings',
		'nFusion Settings',
		'manage_options',
		'nfusion_ppc_settings',
		'nfusion_ppc_settings_page',
		'',
		80
	);
}

// Callback function to display settings page
function nfusion_ppc_settings_page() {
	// check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if(is_plugin_active('action-scheduler/action-scheduler.php') && get_option('nfusion_enable_product_reindexing') == 'yes') {
		// update re-indexing scheduled action interval if applicable
		$prev_reindexing_int = get_option('nfusion_prev_product_reindexing_interval');
		$reindex_int = get_option('nfusion_product_reindexing_interval');
		if ( $prev_reindexing_int != $reindex_int ) {
			as_unschedule_all_actions( 'nfs_catalog_plugin_product_reindexing' );
			update_option('nfusion_prev_product_reindexing_interval', $reindex_int);
			schedule_product_reindexing();
		}
	}

	// check if the user have submitted the settings
	// WordPress will add the "settings-updated" $_GET parameter to the url
	if ( isset( $_GET['settings-updated'] ) ) {
		// add settings saved message with the class of "updated"
		add_settings_error( 'nfusion_messages', 'nfusion_message', __( 'Settings Saved', 'nfusion' ), 'updated' );
	}

	// show error/update messages
	settings_errors( 'nfusion_messages' );
	?>
    <div class="nfusion-settings wrap">
        <div class="toast-container top-0 end-0 p-3">
            <!-- Toasts will be added here via JS -->
        </div>
        <h2><?php _e('nFusion Settings', 'nfusion'); ?></h2>
        <div id="nfusion-tabs">
            <ul>
                <li><a href="#nfusion-tab-catalog">Catalog</a></li>
                <li><a href="#nfusion-tab-pricing">Pricing</a></li>
                <li><a href="#nfusion-tab-advanced">Advanced</a></li>
            </ul>

            <div id="nfusion-tab-catalog">
                <form action="options.php" method="post">
					<?php
					settings_fields('nfusion_catalog_settings');
					do_settings_sections('nfusion_settings_page_catalog');
					submit_button();
					?>
                </form>
            </div>
            <div id="nfusion-tab-pricing">
                <form action="options.php" method="post">
					<?php
					settings_fields('nfusion_pricing_settings');
					do_settings_sections('nfusion_settings_page_pricing');
					submit_button();
					?>
                </form>
            </div>
            <div id="nfusion-tab-advanced">
                <form action="options.php" method="post">
					<?php
					settings_fields('nfusion_advanced_settings');
					do_settings_sections('nfusion_settings_page_advanced');
					submit_button();
					?>
                </form>
            </div>
        </div>
    </div>
	<?php
}

function nfusion_add_catalog_settings() {
	// Add catalog settings fields section
	add_settings_section('nfusion_catalog_settings_section', 'Catalog Integration', 'nfusion_catalog_settings_section_desc', 'nfusion_settings_page_catalog');

	// Register Tenant Alias field
	register_setting('nfusion_catalog_settings', 'nfusion_tenant_alias', array('sanitize_callback' => 'sanitize_text_field', 'default' => NULL));
	add_settings_field('nfusion_tenant_alias_field', 'Tenant Alias', 'nfusion_render_field', 'nfusion_settings_page_catalog', 'nfusion_catalog_settings_section', array('option_name' => 'nfusion_tenant_alias'));

	// Register API Token field
	register_setting('nfusion_catalog_settings', 'nfusion_api_token', array('sanitize_callback' => 'sanitize_text_field', 'default' => NULL));
	add_settings_field('nfusion_api_token_field', 'API Key', 'nfusion_render_field', 'nfusion_settings_page_catalog', 'nfusion_catalog_settings_section', array('option_name' => 'nfusion_api_token'));

	// Register Sales Channel field
	register_setting('nfusion_catalog_settings', 'nfusion_sales_channel', array('sanitize_callback' => 'sanitize_text_field', 'default' => NULL));
	add_settings_field('nfusion_sales_channel_field', 'Sales Channel', 'nfusion_render_field', 'nfusion_settings_page_catalog', 'nfusion_catalog_settings_section', array('option_name' => 'nfusion_sales_channel'));
}
add_action('admin_init', 'nfusion_add_catalog_settings');

function nfusion_add_pricing_settings() {
	// Add pricing settings fields section
	add_settings_section('nfusion_pricing_settings_section', 'Pricing Settings', 'nfusion_pricing_settings_section_desc', 'nfusion_settings_page_pricing');

	// Register pricing refresh interval
	register_setting('nfusion_pricing_settings', 'nfusion_price_update_interval', array('sanitize_callback' => 'nfusion_sanitize_number_field', 'default' => NFS_CATALOG_DEFAULT_PRICE_UPDATE_INTERVAL_SECONDS));
	add_settings_field(
		'nfusion_price_update_interval_field', 'Product price update interval (in sec)',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_price_update_interval', 'type' => 'number', 'min' => '10')
	);

	// Register lowest price label
	register_setting('nfusion_pricing_settings', 'nfusion_low_price_label', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'As low as'));
	add_settings_field(
		'nfusion_lowest_price_label_field', 'Label for lowest price',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_low_price_label')
	);

	// Register show buy price
	register_setting('nfusion_pricing_settings', 'nfusion_show_buy_price', array('sanitize_callback' => 'nfusion_sanitize_checkbox_field', 'default' => 'no'));
	add_settings_field(
		'nfusion_show_buy_price_field', 'Show buy price',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_show_buy_price', 'type' => 'checkbox')
	);

	// Register buy price label
	register_setting('nfusion_pricing_settings', 'nfusion_buy_price_label', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'We buy at'));
	add_settings_field(
		'nfusion_buy_price_label_field', 'Label for buy price',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_buy_price_label')
	);

	// Register show tiered pricing
	register_setting('nfusion_pricing_settings', 'nfusion_show_tiered_pricing', array('sanitize_callback' => 'nfusion_sanitize_checkbox_field', 'default' => 'no'));
	add_settings_field(
		'nfusion_show_tiered_pricing_field', 'Show tiered pricing',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_show_tiered_pricing', 'type' => 'checkbox')
	);

	// Register check price label
	register_setting('nfusion_pricing_settings', 'nfusion_pricing_check_label', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'Check'));
	add_settings_field(
		'nfusion_pricing_check_label_field', 'Check column label',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_pricing_check_label')
	);

	// Register enable credit card
	register_setting('nfusion_pricing_settings', 'nfusion_show_credit_card_price', array('sanitize_callback' => 'nfusion_sanitize_checkbox_field', 'default' => 'no'));
	add_settings_field(
		'nfusion_show_credit_card_price_field', 'Enable credit card price',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_show_credit_card_price', 'type' => 'checkbox')
	);

	// Register card price label
	register_setting('nfusion_pricing_settings', 'nfusion_pricing_card_label', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'Card'));
	add_settings_field(
		'nfusion_pricing_card_label_field', 'Credit card column label',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_pricing_card_label')
	);

	// Register credit card percent
	register_setting('nfusion_pricing_settings', 'nfusion_cc_price', array('sanitize_callback' => 'nfusion_sanitize_number_field', 'default' => NULL));
	add_settings_field(
		'nfusion_cc_price_field',
		'Credit card fee (in %) <span class="nfusion-tooltip dashicons dashicons-info-outline" data-bs-toggle="tooltip" data-bs-placement="top"
        data-bs-custom-class="custom-tooltip"
        data-bs-title="When enabled, this fee is shown in tiered pricing and applied at checkout."></span>',
		'nfusion_render_field', 'nfusion_settings_page_pricing',
		'nfusion_pricing_settings_section', array('option_name' => 'nfusion_cc_price', 'type' => 'number', 'min' => '0', 'step' => '0.01')
	);
}
add_action('admin_init', 'nfusion_add_pricing_settings');

function nfusion_add_advanced_settings() {
	// Add advanced settings fields section
	add_settings_section('nfusion_advanced_settings_section', 'Technical Settings', 'nfusion_advanced_settings_section_desc', 'nfusion_settings_page_advanced');

	// register_setting('nfusion_advanced_settings', 'nfusion_clear_product_pricing_cache', array('default' => 'clear_cache'));
	// Add clear cache button
	add_settings_field(
		'nfusion_clear_product_pricing_cache_field',
		'Clear Product Pricing Catalog Cache <span class="nfusion-tooltip dashicons dashicons-info-outline" data-bs-toggle="tooltip" data-bs-placement="top"
        data-bs-custom-class="custom-tooltip"
        data-bs-title="Empties the product pricing cache and retrieves new prices from your catalog"></span>',
		'nfusion_render_field', 'nfusion_settings_page_advanced',
		'nfusion_advanced_settings_section', array('option_name' => 'nfusion_clear_product_pricing_cache', 'type' => 'button', 'attr' => array('text' => 'Clear Cache', 'value' => 'clear_cache', 'class' => 'clear-cache btn btn-outline-danger'))
	);

	// Check if Action Scheduler plugin is active
	if (is_plugin_active('action-scheduler/action-scheduler.php')) {
		// Register enable product reindexing field
		register_setting('nfusion_advanced_settings', 'nfusion_enable_product_reindexing', array('sanitize_callback' => 'nfusion_sanitize_checkbox_field', 'default' => 'no'));
		add_settings_field(
			'nfusion_product_reindexing_field', 'Enable Product Re-indexing',
			'nfusion_render_field', 'nfusion_settings_page_advanced',
			'nfusion_advanced_settings_section', array('option_name' => 'nfusion_enable_product_reindexing', 'type' => 'checkbox')
		);

		// Register reindexing interval field
		register_setting('nfusion_advanced_settings', 'nfusion_product_reindexing_interval', array('sanitize_callback' => 'nfusion_sanitize_number_field', 'default' => NFS_CATALOG_DEFAULT_REINDEXING_INTERVAL_MINUTES));
		add_settings_field(
			'nfusion_product_reindexing_interval_field', 'Product Re-indexing interval',
			'nfusion_render_field', 'nfusion_settings_page_advanced',
			'nfusion_advanced_settings_section', array('option_name' => 'nfusion_product_reindexing_interval', 'type' => 'number', 'min' => '1')
		);

		// Register hidden previous reindexing interval field
		register_setting('nfusion_advanced_settings', 'nfusion_prev_product_reindexing_interval', array('sanitize_callback' => 'nfusion_sanitize_number_field', 'default' => get_option('nfusion_product_reindexing_interval')));
		add_settings_field(
			'nfusion_prev_product_reindexing_interval_field', '',
			'nfusion_render_field', 'nfusion_settings_page_advanced',
			'nfusion_advanced_settings_section', array('option_name' => 'nfusion_prev_product_reindexing_interval', 'type' => 'hidden', 'value_override' => 'nfusion_product_reindexing_interval')
		);
	} else {
		// Action Scheduler plugin is not active, show a message
		add_settings_field(
			'nfusion_product_reindexing_dependency_message', 'Product Re-Indexing',
			'nfusion_render_missing_dependency_message', 'nfusion_settings_page_advanced',
			'nfusion_advanced_settings_section'
		);
	}
}
add_action('admin_init', 'nfusion_add_advanced_settings');

// Callback function for product re-indexing message
function nfusion_render_missing_dependency_message() {
	echo '
    <p>
        Our Product Catalog Plugin for WooCommerce leverages the Action Scheduler plugin for automating product re-indexing on your WordPress website.
    </p>
    <p>
        <strong>Action Scheduler:</strong><br>
        Action Scheduler is a robust job queue designed for processing large queues of tasks in WordPress. It offers scalability and traceability for background processing, without the need for server access. Developed and maintained by Automattic, the creators of WordPress & WooCommerce, with initial development contributions from Flightless.
    </p>
    <p>
        <strong>Usage:</strong><br>
        Upon installing the Action Scheduler plugin, our Product Catalog Plugin seamlessly integrates with it to asynchronously re-index your WooCommerce products.
    </p>
    <p>
        To learn more about enabling automatic async product re-indexing, visit our support page 
        <a href="https://nfusionsolutions.com/support/#enable-product-reindexing" target="_blank">here</a>.
    </p>';
}

// Callback function for the settings section title
function nfusion_catalog_settings_section_desc() {
	echo '<p>Enter settings below to connect your nFusion Product Pricing Catalog.</p>';
}

function nfusion_pricing_settings_section_desc() {
	echo '<p>Customize the tiered pricing table displayed on individual product pages and other pricing settings.</p>';
}

function nfusion_advanced_settings_section_desc() {
	echo '<p>For advanced users only!</p>';
}

// Callback function to render fields
function nfusion_render_field($args) {
	$option_name = isset($args['option_name']) ? $args['option_name'] : '';
	if(isset($args['value_override'])) {
		$value = get_option($args['value_override']);
	} else {
		$value = get_option($option_name);
	}

	$type = isset($args['type']) ? $args['type'] : 'text';

	// Check the type of input and render accordingly
	switch ($type) {
		case 'checkbox':
			?>
            <div class="form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="<?php echo $option_name; ?>" <?php checked($value, 'yes'); ?> value="yes" />
            </div>
			<?php
			break;
		case 'button':
			$attr = $args['attr'];
			$text = isset($attr['text']) ? $attr['text'] : '';
			$value = isset($attr['value']) ? $attr['value'] : '';
			$class = isset($attr['class']) ? $attr['class'] : '';
			?>
            <button type="button" class="<?php echo $class; ?>" name="<?php echo $option_name; ?>" value="<?php echo esc_attr($value); ?>" <?php if($value == "clear_cache") echo "currency=\"" . get_woocommerce_currency() . "\""; ?>>
				<?php echo $text ?>
            </button>
			<?php
			break;
		default:
			?>
            <input type="<?php echo $type; ?>" name="<?php echo $option_name; ?>" value="<?php echo esc_attr($value); ?>" <?php if (isset($args['min'])) echo 'min="' . esc_attr($args['min']) . '"'; ?> <?php if (isset($args['step'])) echo 'step="' . esc_attr($args['step']) . '"'; ?>  />
		<?php
	}
}

/**
 * Sanitization/Validation helper functions
 **/

// Sanitize checkbox input
function nfusion_sanitize_checkbox_field($input) {
	// Ensure the input is either 'yes' or 'no'
	return ($input === 'yes') ? 'yes' : 'no';
}

// Sanitize number input with min/max constraints
function nfusion_sanitize_number_field($input) {
	return floatval($input);
}
?>
