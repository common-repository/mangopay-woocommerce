<?php
/**
 * @package MANGOPAY-woocommerce
 * @author WP&Co, MANGOPAY
 * @version 3.5.2
 * @see: https://github.com/Mangopay/wordpress-plugin
 */

/*
 Plugin Name: MANGOPAY WooCommerce plugin
 Plugin URI: http://www.mangopay.com/
 Description: Official WooCommerce checkout gateway for the <a href="https://www.mangopay.com/">MANGOPAY</a> payment solution dedicated to marketplaces.
 Version: 3.5.2
 Author: WP&Co, MANGOPAY
 Author URI: https://wpand.co/
 Text Domain: mangopay
 Domain Path: /languages
 Requires at least: 4.4
 Tested up to: 6.2
 Stable tag: 3.5.2
 Requires PHP: 7.0
 License: GPL2

 WC requires at least: 2.6.1
 WC tested up to: 7.1.1
 */

/**
 * @copyright 2015-2018 WP&Co and MANGOPAY ( email : yann _at_ wpandco.fr )
 *
 *  Original development of this plugin was kindly funded by MANGOPAY ( https://www.mangopay.com/ )
 *
 *	Official free support forum:
 * @see: https://wordpress.org/support/plugin/mangopay-woocommerce
 *
 *  Contributing developers: Yann Dubois, Silver, Nicolas Gulian, Hugo Bailey
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Revision 3.5.2
 * - Public stable 3.5 release of 2023/05/31
 * Revision 3.5.1
 * - Public stable 3.5 release of 2022/10/14
 * Revision 3.4.7
 * - Bugfix release of 2022/06/21
 * Revision 3.4.6
 * - Bugfix release of 2022/03/21
 * Revision 3.4.5:
 * - Compatibility release of 2022/01/13
 * Revision 3.4.4:
 * - Compatibility release of 2021/12/10
 * Revision 3.4.3:
 * - Bugfix release of 2021/08/20
 * Revision 3.4.2:
 * - Bugfix release of 2021/07/09
 * Revision 3.4.1:
 * - Public stable 3.4 release of 2021/06/25
 * Revision 3.4.0:
 * - Not released
 * Revision 3.3.4:
 * - Bugfix release of 2020/10/15
 * Revision 3.3.3:
 * - Bugfix release of 2020/09/11
 * Revision 3.3.2:
 * - Compatibility release of 2020/08/26
 * Revision 3.3.1:
 * - Public stable 3.3 release of 2020/05/28
 * Revision 3.2.3:
 * - Bugfix release of 2020/03/27
 * Revision 3.2.2:
 * - Compatibility release of 2020/03/20
 * Revision 3.2.1:
 * - Compatibility release of 2020/02/17
 * Revision 3.2.0:
 * - Bugfix release of 2019/12/17
 * Revision 3.1.1:
 * - Bugfix release of 2019/11/28
 * Revision 3.1.0:
 * - Compatibility release of 2019/11/22
 * Revision 3.0.4:
 * - Bugfix release of 2019/09/25
 * Revision 3.0.3:
 * - Beta internal release of 2019/09/24
 * Revision 3.0.2:
 * - Beta internal release of 2019/09/20
 * Revision 3.0.1:
 * - Public stable 3.0 release of 2019/08/09
 * Revision 2.10.2:
 * - Public stable 2.10 release of 2019/08/05
 * Revision 2.10.1:
 * - Public stable 2.10 release of 2019/06/28
 * Revision 2.9.5:
 * - Compatibility stable release of 2019/06/13
 * Revision 2.9.4:
 * - Bugfix stable release of 2019/06/07
 * Revision 2.9.3:
 * - Bugfix stable release of 2019/04/05
 * Revision 2.9.2:
 * - Bugfix stable release of 2019/02/22
 * Revision 2.9.1:
 * - Public stable v2.9 release of 2019/01/29
 * Revision 2.8.2:
 * - Bugfix release of 2018/11/19
 * Revision 2.8.0:
 * - Public stable v2.8 release of 2018/10/08
 * Revision 2.7.4:
 * - Beta internal release of 2018/09/24
 * Revision 2.7.1:
 * - Beta internal release of 2018/09/20
 * Revision 2.7.0:
 * - Public stable v2.7 release of 2018/09/07
 * Revision 2.6.0:
 * - Public stable v2.6 release of 2018/05/31
 * Revision 2.5.0:
 * - Public stable v2.5 release of 2018/03/08
 * Revision 2.4.2:
 * - Bugfix/compatibility release of 2018/02/01
 * Revision 2.4.1:
 * - Bugfix release of 2017/10/18
 * Revision 2.4.0:
 * - Bugfix release of 2017/08/16
 * Revision 2.3.1:
 * - Bugfix release of 2017/08/03
 * Revision 2.3.0:
 * - Public stable v2.3 release of 2017/07/21
 * Revision 2.2.1:
 * - Bugfix release of 2017/03/23
 * Revision 2.2.0:
 * - Public stable v2.2 release of 2017/03/07
 * Revision 2.1.0:
 * - Public stable v2.1 release of 2016/12/08
 * Revision 2.0.1:
 * - Bugfix release of 2016/10/04
 * Revision 2.0.0:
 * - Public stable v2 release of 2016/09/14
 * Revision 1.0.3:
 * - Bugfix/compatibility release of 2016/08/24
 * Revision 1.0.2:
 * - Bugfix release of 2016/08/02
 * Revision 1.0.1:
 * - Bugfix release of 2016/07/21
 * Revision 1.0.0:
 * - Public stable v1 release of 2016/06/28
 * Revision 0.4.0:
 * - Public beta v4 release of 2016/05/18
 * Revision 0.3.0:
 * - Public beta v3 release of 2016/04/19
 * Revision 0.2.2:
 * - Bugfix beta release of 2015/04/08
 * Revision 0.2.1:
 * - Public beta release of 2015/03/31
 * Revision 0.1.1:
 * - Alpha release 2 of 2015/03/15
 * Revision 0.1.0:
 * - Original alpha release 00 of 2015/02/26
 */

$version = '3.5.2';

/** Custom classes includes **/
include_once( dirname( __FILE__ ) . '/inc/conf.inc.php' );			// Configuration class
include_once( dirname( __FILE__ ) . '/inc/hooks.inc.php' );			// Action and filter hooks class (will include the payment gateway class when appropriate)
include_once( dirname( __FILE__ ) . '/inc/plugin.inc.php' );		// Plugin maintenance class 
include_once( dirname( __FILE__ ) . '/inc/main.inc.php' );			// Main plugin class 
include_once( dirname( __FILE__ ) . '/inc/validation.inc.php' );	// User profile field validation methods 
include_once( dirname( __FILE__ ) . '/inc/mangopay.inc.php' );		// MANGOPAY access methods
include_once( dirname( __FILE__ ) . '/inc/webhooks.inc.php' );		// Incoming webhooks handler
if( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX )
	include_once( dirname( __FILE__ ) . '/inc/ajax.inc.php' );		// Ajax methods
	
if( is_admin() )
	include_once( dirname( __FILE__ ) . '/inc/admin.inc.php' );		// Admin specific methods

/** Main plugin class instantiation **/
global $mngpp_o;
$mngpp_o = new mangopayWCMain( $version );
?>