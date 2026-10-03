//this is to enable multi vendo setup, set to true when multi vendo is supported
var isMultiVendo = true;
// 0 = manual dropdown selection , 1 = auto select vendo base on hotspot address, 2 = interface name
var multiVendoOption = 0;

// SBC API base URL — used by portal JS to reach PHP endpoints on the SBC.
// In router-native mode the portal is served by MikroTik, so /api/ doesn't exist
// on the router. Set this to your SBC IP (http://10.0.0.252) so AJAX calls
// reach the SBC directly. Leave empty for same-origin (external/SBC mode).
var sbcApiUrl = "http://10.0.0.252";

//list here all node mcu address for multi vendo setup (static fallback — dynamically loaded from /api/vendo.php)
var multiVendoAddresses = [];


//0 means its login by username only, 1 = means if login by username + password
var loginOption = 0; //replace 1 if you want login voucher by username + password

var dataRateOption = false; //replace true if you enable data rates
//put here the default selected address
var vendorIpAddress = "";

var chargingEnable = false; //replace true if you enable charging, this can be override if multivendo setup

var eloadEnable = false; //replace true if you enable eload, this can be override if multivendo setup

//hide pause time / logout true = you want to show pause / logout button
var showPauseTime = true;

//enable member login, true = if you want to enable member login
var showMemberLogin = true;

//enable extend time button for customers
var showExtendTimeButton = true;

//disable voucher input
var disableVoucherInput = false;

//enable mac address as voucher code
var macAsVoucherCode = false;

var qrCodeVoucherPurchase = false;