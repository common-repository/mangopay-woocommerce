=== MANGOPAY WooCommerce ===

Contributors: ydubois, mangopay
Tags: woocommerce, wc-vendors, marketplace, payment, gateway, shop, store, checkout, multivendor
Requires at least: 4.4.0
Tested up to: 6.2
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Official WooCommerce Payment gateway for the MANGOPAY payment solution dedicated to marketplaces.


== Description ==

MANGOPAY is a payment solution which allows marketplaces to process third-party payments and collect their commissions in a secure and compliant environment. Our wordpress plugins enable an easy and fast integration to create a marketplace from start to finish and process payments. 
Plugged to WC Vendors and WooCommerce’s plugins, the MANGOPAY WooCommerce plugin gives the ability to marketplaces hosted on Wordpress to accept worldwide credit/debit card payments. It enables the management of vendors’ commissions within the wordpress interface, including payouts, transfers, and platform fees collection.

Some key features include:

* Multi-currency support
* Handle international and local payment methods
* Seamlessly escrow and split funds between users
* Safeguard transactions with our fraud prevention tools and built-in anti-fraud engine
* White-label payment page
* PCI DSS, DSP2 and AML4 compliant

For more information please visit [MANGOPAY.COM](https://mangopay.com)

= Plugin usage =

The MANGOPAY plugin simply connects your WordPress with MANGOPAY’s API. The payment workflow is as follows:

* Step 1 : Vendors sign-up to the marketplace. An associated user is created in MANGOPAY’s environment.
* Step 2 : Vendors complete their profile with the right verification documents. The documents are verified by MANGOPAY’s compliance team. 
* Step 3 : Products are assigned to vendors.
* Step 4 : An order is placed. E-money is stored in the buyer’s e-wallet.
* Step 5 : Vendors mark each product as dispatched.
* Step 6 : The marketplace operator marks the order as completed. Funds are now transferred from the buyer’s e-wallet to the vendor’s e-wallet. Marketplace commissions are collected here. 
* Step 7 : The marketplace operator initiates the vendor payout. Funds are paid from the vendor’s e-wallet to his bank account.

= Documentation =

This [document](http://mangopay.com/mgp-wp-documentation/) will guide you through 3 plugin setups which will enable your marketplace to accept worldwide credit/debit card payments, manage vendors, payouts, transfers, and platform fees.

== Installation ==

= Requirements =

* Wordpress website.
* [WooCommerce plugin.](https://www.wordpress.org/plugins/woocommerce/) Allows you to turn your own Wordpress platform into a full featured e-commerce solution.
* [WC Vendors plugin.](https://www.wordpress.org/plugins/wc-vendors/) Allows you to turn your woocommerce-enabled shop into a multi-vendor marketplace.
* [MANGOPAY Account.](https://www.mangopay.com/sign-up/) You must have a MANGOPAY live sandbox or production account.

[youtube https://youtu.be/WR_fvj1iBYg]

= Installation =

Before starting, make sure your WordPress environment meets the requirements listed above. To install the MANGOPAY Woocommerce plugin:

* Go to your WordPress admin plugin section.
* Add new plugin. MANGOPAY WooCommerce.
* Install the plugin (automatic plugin installation is fully supported).
* Activate the plugin.
* From the main left menu choose “MANGOPAY”. It will lead you to the settings page. 
* Enter your ClientId/API key. Save this info.
* Verify your configuration under the “MANGOPAY status”. The various checks should all be in green for your setup to function correctly. 


= Updating =

Automatic updates should work as normal; as always, backup your website before proceeding with the installation.



== Frequently Asked Questions ==

= How much does it cost to use the MANGOPAY as a payment solution? =

Please consult the MANGOPAY pricing page: https://www.mangopay.com/pricing/

= What are the available payment methods? =

MANGOPAY supports local and international methods of payment.
* Credit and debit card payments: CB, VISA, MASTERCARD, Maestro, Diner's Club
* Direct debits: BACS, SEPA Direct Debit
* Direct transfers:  Bankwire, Ibanisation
* Web and mobile payments: Klarna Pay Now (Sofort), Giropay, PayLib, Przelewy24, iDeal, Bancontact/Mister Cash

= What currencies does MANGOPAY support?  =

MANGOPAY Plugin can support the following currencies: EUR, GBP, USD, CHF, NOK, PLN, SEK, DKK, CAD, ZAR.

= Do you have an exhaustive documentation? =

We have an exhaustive FAQ with a lot of answered question around MANGOPAY [here](https://support.mangopay.com/s/?language=en_US)

= How can I display PayPal from the vendor shop settings page? =

See [here](https://www.wcvendors.com/kb/turn-paypal/) on the WC Vendors site

= Can I use a more complicated commission structure? =

Probably, yes! Have a look [here](https://www.wcvendors.com/kb/commissions-fixed-dollar-amount-fixed-plus-percentage/) on the WC Vendors site

= Will the plugin work with my theme? =

Yes! The MANGOPAY plugin is entirely independent of the theme

= Do I have to use WC Vendors and Woocommerce? =

For the time being, yes - but we will possibly make the plugin compatible with other payment gateways and vendor plugins in a future version

= Where can I get support or talk to other users? =

If you get stuck, you can ask for help in the [MANGOPAY Plugin Forum](https://wordpress.org/support/plugin/mangopay-woocommerce). Please supply as much information and detail as you can about your problem

= Can I customise the design of the payment page? =

Sure! Create a normal page with the shortcode [mangopay_payform] and then go to the WooCommerce settings page, and in the "Checkout" tab, choose "MANGOPAY" and then choose the page you just created for the "Use this page for payment template" setting. Note that there are several requirements for the page to be taken into account - see [here](https://docs.mangopay.com/guide/customising-the-design) for more info.

= Using WPEngine.com for your hosting? =

Due to some strict caching on their side, you'll need to manually request something to them in order for the plugin to function correctly. If you login to your [WPEngine account](https://my.wpengine.com/support#general-issue) and open up a live chat session (top right of the page), you should provide the install name or domain name and say you're using the WooCommerce Mangopay payment plugin and need "a cache exclusion for the plugin file path _/wp-content/uploads/mp_tmp/_ and payment gateway call _^/wp-json_ followed by a config refresh". These actions can only be done manually by their lovely support team, and are critical for the plugin to perform correctly.

== Screenshots ==

1. Accept CB, Visa or Mastercards as a WooCommerce checkout payment option
2. Easily manage client and vendor payment transactions from within the WordPress, WooCommerce and WC-Vendors admin screens
3. Simple settings screen and comprehensive status an health-check dashboard all from within de WP admin


== Changelog ==

= 3.5.2 =
* Added support for American Express AMEX card payments
* Improved workflow to convert vendors from Payer to Owner
* Improved back-office view of Owner/vendors requirements
* Improved notifications about missing required Owner/vendor data
* Improved back-office healthchecks regarding missing required Owner/vendor data
* Fixed a bug when saving user data in the back-office and required data was missing
* Added some missing French translations in the back-office
* Ensured full compatibility with WordPress up to version 6.2.2
* Ensured compatibility with WooCommerce plugin up to version 7.1.1
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.4.4

= 3.5.1 =
Stable version 3.5
* Update of the MANGOPAY SDK
* New UserCategory requirements for Users: Payer and Owner categories (Shamrock API update)
* New requirements for Owners: mandatory Headquarter Address for all (Shamrock API update)
* New requirements for Owners: Terms & Conditions checkbox (Bromeliad API update)
* Put back identification header to API requests (erroneously removed in 3.4.x)
* Ensured full compatibility with WordPress up to version 6.0
* Ensured initial compatibility with WordPress 6.1 (beta)
* Ensured compatibility with WooCommerce plugin up to version 7.0.0
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.4.3

= 3.4.7 =
Stable version 3.4
* Fixed typo (missing space) in inc/main.php causing fatal error on some PHP8.x installations
* Fixed use of global $profileuser in inc/main.php causing notice in WP6.x

= 3.4.6 =
Stable version 3.4
* Fixed/improved German Company Numbers checks
* Fixed error "Company number format not recognized" when saving shop settings
* Fixed double notification issues when PAYIN_NORMAL_SUCCEEDED webhook is active
* Fixed warnings when selling is disabled in CA or US
* Improved health-check dashboard in the WP back-office
* Added missing translations in .po/.mo for French language
* Ensured compatibility with WooCommerce plugin up to version 6.3.1
* Ensured compatibility with WC-Vendors plugin up to version 2.4.1
* Ensured full compatibility with WordPress up to 5.9.2
* Ensured initial compatibility with WordPress 6.0 (beta)

= 3.4.5 =
Stable version 3.4
* Fixed compatibility with WC-Vendors 2.4.0 (commissions table)

= 3.4.4 =
Stable version 3.4
* Slight improvements of health-check dashboard
* Bugfix in mangopay_woocommerce_payment_gateway_supports filter hook syntax, props Tobias/IT-1970
* Ensured compatibility with WooCommerce plugin up to version 6.0.0

= 3.4.3 =
Stable version 3.4
* Fixed PAYLINEV2 custom web payment page following 3DS2 upgrade
* Fixed issue with datepicker when using some specific date formats
* Added new insights in the "health-check" plugin dashboard in the WP back office
* Suppressed the back-office warning message about mandatory KYC/UBO compliance for vendors
* Completed changelog of version 3.4.1
* Ensured compatibility with WordPress up to version 5.9
* Ensured compatibility with WooCommerce plugin up to version 5.5.2
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.3.2

= 3.4.2 =
Internal release, same as public release 3.4.1

= 3.4.1 =
Stable version 3.4
* 3DS2 Evolutions
* Fixed redirections to Paylib and Masterpass payment forms
* Hide "Refund" button of WooCommerce back-office (this can be superseded with a hook)
* Added a new "3DS2 force test" feature when in Sandbox environment for debugging purpose
* Improved API error reporting (ie. for missing 3DS2 information)
* Ensured compatibility with WordPress up to version 5.7.2
* Ensured compatibility with WordPress (beta) up to version 5.8-beta3-51224
* Ensured compatibility with WooCommerce plugin up to version 5.4.1
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.2.4

= 3.3.4 =
Stable version 3.3
* Fixed an issue with iDeal payments when only iDeal and VISA/MC were enabled as payment methods
* Removed iDeal from list of cards that can be registered
* Ensured compatibility with WordPress up to version 5.5.1
* Ensured compatibility with WordPress up to alpha version 5.6 (5.6-alpha-49133)
* Ensured compatibility with WooCommerce plugin up to version 4.6.0
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.2.1

= 3.3.3 =
Stable version 3.3
* Fixed an issue with nationality (don't require a state when nationality is US or Canada upon user registration)
* Ensured compatibility with WordPress up to version 5.5.1
* Ensured compatibility with WordPress up to beta version 5.6 (5.6-alpha-48969)
* Ensured compatibility with WooCommerce plugin up to version 4.5.1
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.2.1

= 3.3.2 =
Stable version 3.3
* Added an identification header to API requests
* Fixed compatibility with WordPress 5.5 (removal of jQuery Migrate)
* Improved versioning of static files to avoid cache issues (CSS and JS files are now versioned with plugin release number)
* Ensured compatibility with WordPress up to version 5.6 (alpha)
* Ensured compatibility with WooCommerce plugin up to version 4.4.1
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.2.0

= 3.3.1 =
Stable version 3.3
* Added a new "Instant payout" setting to compensate for the removal of this option in WC-Vendors
* Added a new indicator in the Users list back-office to visualize UBO status of vendors
* Added new filters in the code to allow precise custom commission handling
* Fixed handling of vendor commissions with taxes for recent versions of WC-Vendors
* Fixed handling of vendor commissions with shipping for recent versions of WC-Vendors
* Improved consistency of commission handling when not using the "Instant payout" feature
* Improved French translations (added a few missing phrases)
* Ensured compatibility with WordPress up to version 5.4.1
* Ensured compatibility with WooCommerce plugin up to version 4.1.1
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.20

= 3.2.3 =
Bugfix release
* Fixed a bug that caused the order-received page to crash when using pre-authorization
* Improved error-reporting in the order-received page

= 3.2.2 =
Compatibility release
* Ensured compatibility with WordPress up to version 5.4
* Ensured compatibility with WooCommerce plugin up to version 4.0.1
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.19
* Fixed compatibility issue with WooCommerce 3.9 and above (warning when payment was cancelled)
* Added display of PHP and Curl-lib versions in the MANGOPAY health-check dashboard
* Improved handling of KYCs in the back-office when a lot of requests are pending

= 3.2.1 =
Compatibility release
* Ensured compatibility with WordPress up to version 5.3.2
* Ensured compatibility with WooCommerce plugin up to version 3.9.2
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.18

= 3.2.0 =
Stable version 3.2
* Fixed issues with UBO validations in the Vendor Settings back-office with WC-Vendors Pro
* Added some translations to the UBO validation process

= 3.1.1 =
Bugfix of Stable version 3.1
* Fixed a bug where pre-authorized card payments did not complete without a webhook
* Fixed issues with UBO validations in the Vendor Settings back-office with WC-Vendors Pro
* Ensured compatibility with WordPress up to version 5.3.2
* Ensured compatibility with WooCommerce plugin up to version 3.8.1

= 3.1.0 =
Stable version 3.1
* Improved synchronization of KYC statuses with the API
* Fixed a bug that prevented UBOs to be managed from the back-office with WC-Vendors Pro
* Ensured compatibility with WordPress up to version 5.3
* Ensured compatibility with WooCommerce plugin up to version 3.8.0
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.16

= 3.0.4 =
Bugfixes of Stable version 3.0
* Added "headquarter address" fields
* Updated vendor payout validation to only take into account a vendor's present KYC status
* Added a hook "mangopay_payout_success" after a successful payout: this would allow to trigger third-party events such as sending additional alert e-mails
* Added some missing French translations in the admin
* Fixed a bug that prevented the Client ID to contain non-alphanumeric characters
* Fixed a bug that caused a fatal error when the company number contained spaces
* Fixed a bug in the back-office vendor validation checks
* Fixed a bug that caused a fatal error when processing orders with deleted products
* Ensured compatibility with WordPress up to version 5.2.3
* Ensured compatibility with WooCommerce plugin up to version 3.7.0
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.15

= 3.0.1 =
Stable version 3.0
* Conforms to new regulations of September 2019 concerning online payments

= 2.10.2 =
Stable version 2.10
* Added Company number pattern tests
* Added UBO form for WC vendors (2.1.11) and WC vendors pro (1.6.4)
* Updated the health check for company numbers to include the patterns

= 2.10.1 =

Stable version 2.10 (updated PHP-SDK)
* Updated the MANGOPAY PHP-SDK to latest an did necessary adaptations in the plugin core
* Fixed a bug that caused a blank page ont theme edition in the wp-admin
* Fixed a missing French translation
* Ensured compatibility with WordPress up to version 5.2.2
* Ensured compatibility with WooCommerce plugin up to version 3.6.4
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.12

= 2.9.5 =

Stable version 2.9 (compatibility release for WC-Vendors 2.1.10 and 2.1.11)
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.11

= 2.9.4 =

Stable version 2.9 (with calendar widget fixed in user-edit and preparing for regulatory changes of September 2019)
* Fixed a bug with the calendar widget that caused malfunction of the user-edit screen in the wp-admin
* Important warning messages to prepare for regulatory changes of September 2019
* Added a company number id field in the vendor dashboards (will be mandatory for all business legal users starting 1st September 2019)
* Added health-checks to help for compliance with upcoming regulatory changes
* Added user compliance information in the users list of the wp-admin
* Ensured compatibility with WordPress up to version 5.2.1
* Ensured compatibility with WooCommerce plugin up to version 3.6.2
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.9

= 2.9.3 =

Stable version 2.9 (with vendor bank account change fixed)
* Fixed a bug that prevented modifying vendor bank account information from the vendor front-end dashboard
* Fixed a bug that prevented modifying a user's country when switching between mandatory and non-mandatory state countries
* Fixed a bug that prevented uploading of images in the vendor dashboard with WC-Vendors Pro
* Small code improvements for better health-checks and diagnostics
* Ensured compatibility with WordPress up to version 5.1.1
* Ensured compatibility with WooCommerce plugin up to version 3.5.7
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.7

= 2.9.2 =

Stable version 2.9 (with SDK fixed)
* Fixed a bug in the SDK Oauth URLs that caused 404 Not Found errors on some setups
* Removed unused debug messages

= 2.9.1 =

Stable version 2.9

* Improved card registration
* Improved pre-authorized payments
* Improved tracking and reporting of incoming webhooks 
* Improved incoming webhook management to avoid potential duplicate order processing
* Improved French translation
* Added support for partial-capture of pre-authorized payments (beta)
* Added support for pre-authorized payment captures in vendors dashboard (beta)
* Ensured compatibility with WordPress up to version 5.0.3
* Ensured compatibility with WooCommerce plugin up to version 3.5.3
* Ensured compatibility with WC-Vendors Marketplace plugin up to version 2.1.4

= 2.8.2 =

Stable version 2.8

* Bugfix: will no longer attempt to carry out "zero amount" commission transfer for free product orders
* Bugfix: fixed triggering of automatic wallet transfers on virtual + downloadable products

= 2.8.0 =

Stable version 2.8

* Improved support for card registration (beta)
* Added support for pre-authorized payments (beta)
* Fixed bug involving roles/capability checks with specific table prefixes (props @noobanooba and @oelita)
* Improved hook priority avoiding potential problem with triggering of commission transfers (props @oelita)
* Ensured compatibility with WordPress up to version 5.0-alpha-42606
* Ensured official compatibility with WC-Vendors up to version 2.1.1

= 2.7.0 =

Stable version 2.7

* Added support for card registration (beta)
* Ensured compatibility with the new MANGOPAY dashboard (changed links in the admin)
* Fixed bug that caused 'Fatal error: Call to undefined function get_user_by()' when first registering settings
* Fixed plugin version recording in the options
* Ensured official compatibility with WordPress up to version 4.9.8
* Ensured official compatibility with WooCommerce up to version 3.4.5
* Ensured official compatibility with WC-Vendors up to version 2.1.0

= 2.6.0 =

Stable version 2.6

* Added support for correct dispatching of commissions when VAT is enabled
* Added support for correct dispatching of commissions when shipping is enabled (incl. shipping fees with VAT)
* Ensured that shipping cost is now correctly included and dispatched in payouts
* Improved error management when an erroneous API login is provided
* Improved handling of the API login to prevent invisible whitespace
* Added `mangopay_payment_available_card_types` filter hook to allow customization of the checkout payment fields
* Added `mangopay_payment_available_directdebit` filter hook to allow customization of the checkout payment fields
* Added `mangopay_payment_html` filter hook to allow customization of the checkout payment fields
* Ensured official compatibility with WordPress up to version 4.9.6
* Ensured official compatibility with WooCommerce up to version 3.4.0
* Ensured official compatibility with WC-Vendors up to version 2.0.6
* Changed wording: "Passphrase" is now referred to as "API Key" to stay consistent with MANGOPAY documentation

= 2.5.0 =

Stable version 2.5

* Added support for the Sofort direct web payin method
* Added support for the Giropay direct web payin method
* Ensured official compatibility with WordPress up to version 5.0-alpha
* Ensured official compatibility with WooCommerce up to version 3.3.3
* Ensured official compatibility with WC-Vendors up to version 1.9.14
* Fixed notice for Undefined index: default_business_type due to misnamed default config value
* Fixed "Error: wrong payment amount." Shown Incorrectly (props Michael Compton)
* Fixed integer conversion problems leading to wrongly rounded values (props Michael Compton)

= 2.4.2 =

Bugfix/compatibility release version 2.4.2

* Ensured official compatibility with WordPress up to version 4.9.2
* Ensured official compatibility with WooCommerce up to version 3.2.6
* Ensured official compatibility with WC-Vendors up to version 1.9.13
* Added `mp_allowed_currencies` filter hook applied inside the load_config() function in admin.inc.php
* Added `mp_account_types` filter hook applied inside the load_config() function in admin.inc.php
* Added `mp_commission_due` filter hook to the $total_due inside the vendor_payouts() function in admin.inc.php
* Added `total_shipping` field to values retrieved from the database when calculating vendor_payouts (passed in the `mp_commission_due` filter)
* Added a new status check in the MP status dashboard to issue a warning when products are attributed to admins instead of vendors
* Fixed admin dashboard being blocked when API calls to retrieve failed payouts and fayed KYCs crashed
* Fixed WooCommerce 3.x deprecation warnings when directly accessing order properties
* Fixed PHP 7.1 deprecation warnings: openssl_encrypt() will now be used instead of mcrypt when available
* Improved error and logging of failed payment attempts when the proper user creation workflow was not respected (ie when users are missing mandatory info such as birthdate or nationality)

= 2.4.1 =

Bugfix release version 2.4.1

* Fixed some warning messages related to WooCommerce version 3.x
* Fixed some issues with images in the front-end dashboard with WC-Vendors Pro
* Fixed some front-end styling/display issues with WC-Vendors Pro
* Fixed js conflict issues with WC-Vendors Pro Pro and select2
* Added versioning of CSS and JS scripts
* Prevented rare fatal errors when API is not responding

= 2.4.0 =

Bugfix release version 2.4.0

* Fixed region field not showing correctly on the bank account form
* Fixed support for variable products created via the back-office WC admin
* Reverted partial support of different currencies in the same marketplace

= 2.3.1 =

Bugfix release version 2.3.1

* Fixed KYC doc upload form compatibility issues with WC-Vendors Pro version

= 2.3.0 =

Stable version 2.3:

* Added KYC doc upload form
* Made postal code optional for various countries
* Fixed "User status is required" error in some contexts
* Fixed currency issues with multilingual WooCommerce
* Fixed multiple problems with the "State/County" field
* Fixed some date translation issues with exotic multilingual support
* Added an order note to indicate when in sandbox or production mode
* Checked compatibility with WP 4.8, WC 3.0.7 and WV 1.9
* Added compatibility with the BuddyPress subscription form

= 2.2.1 =

Bugfix release version 2.2.1

* The "Business type" field can now be changed for existing users
* The "User status" is now shown when creating a new user in the wp-admin
* The "Business type" field is removed in the wp-admin for individual users
* The "Business type" field is now correctly enforced in all cases when creating a new user, which will allow creation of the MP user
* Vendor defaults are applied to pending vendors
* User creation defaults have been clarified in all cases

= 2.2.0 =

Stable version 2.2:

* Changed page shown when payments fail or are cancelled (now goes back to checkout)
* Implemented WooCommerce native select2 fields for Country + County/State selectors
* Enforced mandatory County/State data for specific coutries (US, MX, CA)
* Fixed a bug with business type field not showing in some cases
* Fixed a bug where custom payment pages were always used
* Fixed a bug with dates containing accents
* Fixed a bug with webhook verifications
* Improved field validation error messages for bank account data
* Internal code refactoring
* Checked compatibility with WordPress 4.7.1, 4.7.2, 4.7.3 and 4.8

= 2.1.0 =

Stable version 2.1:

* Support of Bankwire Direct payins
* Support of incoming webhooks for Bankwire Direct
* Automatic webhook management (setup / update)
* Improved transaction ID handling
* Improved internal storage of transaction references
* Improved temporary file storage
* Fixed minor PHP notices
* Internal code refactoring
* Checked compatibility with WC-Vendors 1.9.4
* Checked compatibility with WooCommerce 2.6.4
* Checked compatibility with WordPress 4.7

= 2.0.1 =

Bugfix release version 2.0.1

* Fixes a fatal error with set_commission_paid() when completing orders in the WooCommerce admin panel

= 2.0.0 =

Stable version 2:

* Support of optional payment template URL
* Support of the soletrader status
* Fixed bug with unspecified legacy user nationality in the checkout form (will now correctly trigger an error)
* Optimized front-end performance (admin code is no longer loaded when on the front)
* Major code refactoring
* Checked compatibility with WC-Vendors 1.9.3

= 1.0.3 =

Bugfix/compatibility release 3:

* Fixed compatibility with WC Vendors Pro plugin (save bank account info in the front-end store dashboard)
* Fixed potential compatibility issue with third-party plugins (when overriding checkout fields)

= 1.0.2 =

Bugfix release 2:

* Fixed PHP7 issue with bank account data display

= 1.0.1 =

Bugfix release 1:

* Fixed textual date format issues for localized installations that caused birthday date validation errors
* Fixed vendor role detection on some specific setups that prevented the bank account fields to show up
* Cleared PHP warning message for an unset variable when creating a bank account

= 1.0.0 =

Stable version 1:

* Fixed birthday format validation
* Complete localization of birth date format (now uses WordPress date format option)
* Improved birthday date picker (default year + year drop-down)
* Improved admin notices (more health checks)
* Added support for WC Vendor's "Instapay" feature (instant payment/auto-payouts)
* Added a "failed payout" admin dashboard, with transaction ignore/retry features
* Added a "refused KYC document" admin dashboard
* Tested successfully up to WP 4.5.3, WC 2.6.1 and WC Vendors 1.9.1

= 0.4.0 =

Public beta v4:

* Fixed bug that prevented MP user creation when WP users were created while the plugin was inactive
* Fixed bug that caused card type value to be lost upon ajax update of the checkout page
* Fixed bug that prevented wallet transfers to vendors to be performed with virtual/downloadable/bookable products
* Improved synchronization of bank account data
* Improved bank account data validation
* Added a health-check to ensure at least one payment method is enabled
* Improved credit card selection admin

= 0.3.0 =

Public beta v3:

* Improved natural/legal user management
* Vendors can now correctly manage their bank account data from their WC-Vendor shop settings page
* Avoid crashes when an API connection problem occurs upon MANGOPAY user account creation

= 0.2.2 =

First bugfixes for beta version:

* Avoid error 500 when WooCommerce plugin not activated
* Gateway correctly enabled upon initial plugin activation
* Updated admin notices when setup is incomplete

= 0.2.1 =

Full-featured public beta version.

= 0.1.1 =

Full-featured early beta version.
