/*
 * AIRCOINS NETFI — Portal Configuration
 *
 * These globals are read by core.js at runtime.
 * Loaded in <head> before core.js so values are available on DOMContentLoaded.
 */

// ── Vendo / Coin-Slot ──────────────────────────────────────────────────────
var isMultiVendo       = true;   // true = fetch device list from SBC API
var multiVendoOption   = 0;      // 0 = dropdown, >0 = force specific device index
var sbcApiUrl          = "http://10.0.0.252";  // SBC base URL (portal runs on router, API lives here)
var multiVendoAddresses = [];    // populated by fetchVendoDevices()

// ── Login ───────────────────────────────────────────────────────────────────
var loginOption        = 0;      // 0 = voucher-only, 1 = voucher+member
var macAsVoucherCode   = false;  // true = auto-fill MAC as voucher
var disableVoucherInput = false; // true = lock the voucher field

// ── Display ─────────────────────────────────────────────────────────────────
var showPauseTime       = true;
var showMemberLogin     = true;
var showExtendTimeButton = true;
var qrCodeVoucherPurchase = false;

// ── Vendor (legacy, unused in pure Piso WiFi) ──────────────────────────────
var dataRateOption  = false;
var vendorIpAddress = "";
