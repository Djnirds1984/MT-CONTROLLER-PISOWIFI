/*
 * ============================================================
 *  AIRCOINS NETFI — NodeMCU ESP8266 Vendo Firmware (v2)
 * ============================================================
 *
 *  Role:  Coin-operated WiFi vending machine with one-time setup mode.
 *
 *  Two operating modes:
 *    1. SETUP MODE — Creates open AP "aircoins_coinslot_setup" with
 *       captive portal at /config for WiFi configuration. Triggered by:
 *       - Holding SETUP button (GPIO D3) during boot, OR
 *       - First boot (no saved credentials in SPIFFS)
 *
 *    2. NORMAL MODE — Connects to configured Mikrotik hotspot as STA,
 *       runs coin-slot vendo logic, serves voucher API to portal.
 *
 *  Setup Portal (/config):
 *    - WiFi scan button → lists available SSIDs
 *    - Dropdown to select SSID + password field
 *    - "Connect & Save" → saves credentials to SPIFFS, reboots to STA mode
 *
 *  Normal Mode Endpoints:
 *    GET  /status               JSON: MAC, IP, uptime, connection state
 *    GET  /data/{mac}.txt       Read voucher data file
 *    GET  /getRates             Promo rates (pipe-delimited)
 *    POST /checkCoin            Poll coin status
 *    POST /generateVoucher      Generate voucher for a MAC
 *    POST /cancelTopUp          Cancel pending top-up
 *
 *  Hardware:
 *    - NodeMCU ESP8266 (ESP-12E / ESP-12F)
 *    - Coin acceptor  →  GPIO D2 (pin 4), active-LOW pulse
 *    - Setup button   →  GPIO D3 (pin 0), active-LOW (built-in pullup)
 *    - Status LED     →  GPIO D4 (pin 2, built-in LED, active-LOW)
 *
 *  Dependencies (Arduino IDE Board Manager):
 *    - esp8266 by ESP8266 Community  (v3.x)
 *    Libraries:
 *    - ESP8266WiFi
 *    - ESP8266WebServer
 *    - SPIFFS  (built-in)
 *    - ArduinoJson v6
 *
 *  Upload:
 *    Board:  "NodeMCU 1.0 (ESP-12E Module)"
 *    Flash:  4M (3M SPIFFS)
 *    Port:   your COM port
 * ============================================================
 */

#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <FS.h>           // SPIFFS
#include <ArduinoJson.h>  // v6 — install via Library Manager

/* ============================================================
 * 1. CONFIGURATION
 * ============================================================ */

// Setup mode
static const char* SETUP_AP_SSID     = "aircoins_coinslot_setup";
static const char* SETUP_AP_PASSWORD = "";  // empty = open AP
static const uint8_t PIN_SETUP_BTN   = 0;   // GPIO D0 (active-LOW)

// Pin definitions
static const uint8_t PIN_COIN        = 4;   // GPIO D2 — coin acceptor
static const uint8_t PIN_LED         = 2;   // GPIO D4 — status LED (active-LOW)

// Voucher settings
static const char*   VOUCHER_PREFIX  = "AIR";
static const uint8_t VOUCHER_LEN     = 6;

// Coin pulse debounce (ms)
static const uint16_t COIN_DEBOUNCE_MS = 150;

// HTTP server port
static const uint16_t HTTP_PORT = 80;

// SPIFFS paths
static const char* CRED_FILE = "/wifi_cred.json";

/* ============================================================
 * 2. GLOBAL STATE
 * ============================================================ */

ESP8266WebServer  server(HTTP_PORT);

// Operating mode
bool setupMode = false;

// WiFi credentials (loaded from SPIFFS)
String savedSsid     = "";
String savedPassword = "";

// Coin acceptor state
volatile uint16_t coinPulseCount   = 0;
uint16_t          coinTotal        = 0;
uint32_t          lastPulseMs      = 0;

// Voucher state
String pendingVoucher;
String pendingMac;
uint32_t voucherGeneratedAtMs = 0;

// Promo rates (pipe-delimited: code|name|price|group|hash)
static const char* PROMO_RATES =
  "RATE1|1 Hour|5.00|Time|hash1\n"
  "RATE2|2 Hours|10.00|Time|hash2\n"
  "RATE3|1 Day|25.00|Time|hash3\n"
  "RATE4|1 Week|100.00|Time|hash4\n";

/* ============================================================
 * 3. FORWARD DECLARATIONS
 * ============================================================ */

void setupModeInit();
void normalModeInit();

void handleSetupRoot();
void handleConfigPage();
void handleScanWiFi();
void handleSaveCredentials();

void handleNormalRoot();
void handleStatus();
void handleDataFile();
void handleGetRates();
void handleCheckCoin();
void handleGenerateVoucher();
void handleCancelTopUp();
void handleNotFound();

String generateVoucherCode();
bool writeDataFile(const String& mac, const String& voucher);
String readDataFile(const String& mac);
void blinkLed(uint8_t times);

bool loadCredentials();
bool saveCredentials(const String& ssid, const String& password);
bool clearCredentials();

/* ============================================================
 * 4. CREDENTIAL STORAGE (SPIFFS)
 * ============================================================ */

bool loadCredentials() {
  if (!SPIFFS.exists(CRED_FILE)) {
    return false;
  }
  File f = SPIFFS.open(CRED_FILE, "r");
  if (!f) return false;

  DynamicJsonDocument doc(256);
  DeserializationError err = deserializeJson(doc, f);
  f.close();

  if (err) return false;

  savedSsid     = doc["ssid"] | "";
  savedPassword = doc["password"] | "";

  return savedSsid.length() > 0;
}

bool saveCredentials(const String& ssid, const String& password) {
  DynamicJsonDocument doc(256);
  doc["ssid"]     = ssid;
  doc["password"] = password;

  File f = SPIFFS.open(CRED_FILE, "w");
  if (!f) return false;

  serializeJson(doc, f);
  f.close();
  return true;
}

bool clearCredentials() {
  if (SPIFFS.exists(CRED_FILE)) {
    return SPIFFS.remove(CRED_FILE);
  }
  return true;
}

/* ============================================================
 * 5. COIN ACCEPTOR INTERRUPT
 * ============================================================ */

ICACHE_RAM_ATTR void coinPulseISR() {
  uint32_t now = millis();
  if (now - lastPulseMs < COIN_DEBOUNCE_MS) return;
  lastPulseMs = now;
  coinPulseCount++;
}

/* ============================================================
 * 6. VOUCHER CODE GENERATOR
 * ============================================================ */

String generateVoucherCode() {
  static const char ALPHABET[] = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  String code = VOUCHER_PREFIX;
  for (uint8_t i = 0; i < VOUCHER_LEN; i++) {
    code += ALPHABET[random(sizeof(ALPHABET) - 1)];
  }
  return code;
}

/* ============================================================
 * 7. SPIFFS DATA FILE I/O
 * ============================================================ */

bool writeDataFile(const String& mac, const String& voucher) {
  String path = "/data/" + mac + ".txt";
  if (!SPIFFS.exists("/data")) {
    SPIFFS.mkdir("/data");
  }
  File f = SPIFFS.open(path, "w");
  if (!f) return false;
  String content = voucher + "#" + String(millis());
  f.print(content);
  f.close();
  return true;
}

String readDataFile(const String& mac) {
  String path = "/data/" + mac + ".txt";
  if (!SPIFFS.exists(path)) return "";
  File f = SPIFFS.open(path, "r");
  if (!f) return "";
  String content = f.readString();
  f.close();
  return content;
}

/* ============================================================
 * 8. LED HELPER
 * ============================================================ */

void blinkLed(uint8_t times) {
  for (uint8_t i = 0; i < times; i++) {
    digitalWrite(PIN_LED, LOW);
    delay(100);
    digitalWrite(PIN_LED, HIGH);
    delay(100);
  }
}

/* ============================================================
 * 9. SETUP MODE — Captive Portal Handlers
 * ============================================================ */

void handleSetupRoot() {
  server.sendHeader("Location", "/config", true);
  server.send(302, "text/plain", "Redirecting to /config");
}

void handleConfigPage() {
  String html = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>AIRCOINS NETFI — Vendo Setup</title>
  <style>
    body { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
    h1 { color: #2c3e50; text-align: center; }
    .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
    label { display: block; margin: 10px 0 5px; font-weight: bold; color: #34495e; }
    select, input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    button { width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; margin-top: 15px; }
    button:hover { background: #2980b9; }
    button:disabled { background: #95a5a6; cursor: not-allowed; }
    .status { padding: 10px; border-radius: 4px; margin-top: 15px; text-align: center; }
    .status.info { background: #d1ecf1; color: #0c5460; }
    .status.success { background: #d4edda; color: #155724; }
    .status.error { background: #f8d7da; color: #721c24; }
    .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; margin-right: 8px; vertical-align: middle; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <h1>AIRCOINS NETFI — Vendo Setup</h1>

  <div class='card'>
    <label>Hotspot WiFi Network</label>
    <button id='scanBtn' onclick='scanWiFi()'>Scan Hotspot WiFi</button>
    <div id='scanStatus' class='status info' style='display:none; margin-top:10px;'></div>
    <select id='ssidSelect' style='margin-top:10px;'>
      <option value=''>-- Scan or enter manually --</option>
    </select>
  </div>

  <div class='card'>
    <label>Hotspot Password</label>
    <input type='password' id='wifiPass' placeholder='Enter hotspot password (leave blank if open)'>

    <button id='saveBtn' onclick='saveConfig()'>Connect & Save</button>
    <div id='saveStatus' class='status info' style='display:none; margin-top:10px;'></div>
  </div>

  <script>
    function scanWiFi() {
      var btn = document.getElementById('scanBtn');
      var status = document.getElementById('scanStatus');
      btn.disabled = true;
      status.style.display = 'block';
      status.className = 'status info';
      status.innerHTML = '<span class="spinner"></span> Scanning for WiFi networks...';

      fetch('/scan')
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          if (data.error) {
            status.className = 'status error';
            status.textContent = 'Scan failed: ' + data.error;
            return;
          }
          var select = document.getElementById('ssidSelect');
          select.innerHTML = '<option value="">-- Select SSID --</option>';
          data.networks.forEach(n => {
            var opt = document.createElement('option');
            opt.value = n.ssid;
            opt.textContent = n.ssid + ' (' + n.rssi + ' dBm)' + (n.enc ? ' 🔒' : '');
            select.appendChild(opt);
          });
          status.className = 'status success';
          status.textContent = 'Found ' + data.networks.length + ' networks';
        })
        .catch(err => {
          btn.disabled = false;
          status.className = 'status error';
          status.textContent = 'Scan failed: ' + err;
        });
    }

    function saveConfig() {
      var ssid = document.getElementById('ssidSelect').value;
      var pass = document.getElementById('wifiPass').value;
      var btn = document.getElementById('saveBtn');
      var status = document.getElementById('saveStatus');

      if (!ssid) {
        status.style.display = 'block';
        status.className = 'status error';
        status.textContent = 'Please select or enter an SSID';
        return;
      }

      btn.disabled = true;
      status.style.display = 'block';
      status.className = 'status info';
      status.innerHTML = '<span class="spinner"></span> Saving credentials and connecting...';

      fetch('/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ssid=' + encodeURIComponent(ssid) + '&password=' + encodeURIComponent(pass)
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            status.className = 'status success';
            status.textContent = '✓ Saved! Rebooting to connect to ' + ssid + '...';
            setTimeout(() => { alert('Device is rebooting. Close this page.'); }, 1500);
          } else {
            status.className = 'status error';
            status.textContent = 'Save failed: ' + (data.error || 'unknown');
            btn.disabled = false;
          }
        })
        .catch(err => {
          status.className = 'status error';
          status.textContent = 'Save failed: ' + err;
          btn.disabled = false;
        });
    }
  </script>
</body>
</html>
)rawliteral";

  server.send(200, "text/html", html);
}

void handleScanWiFi() {
  DynamicJsonDocument doc(1024);

  int n = WiFi.scanNetworks();
  if (n < 0) {
    doc["error"] = "WiFi scan failed";
    String out;
    serializeJson(doc, out);
    server.send(500, "application/json", out);
    return;
  }

  JsonArray networks = doc.createNestedArray("networks");
  for (int i = 0; i < n; i++) {
    JsonObject net = networks.createNestedObject();
    net["ssid"]  = WiFi.SSID(i);
    net["rssi"]  = WiFi.RSSI(i);
    net["enc"]   = (WiFi.encryptionType(i) != ENC_TYPE_NONE);
  }

  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handleSaveCredentials() {
  String ssid = server.arg("ssid");
  String pass = server.arg("password");

  if (ssid.length() == 0) {
    DynamicJsonDocument doc(128);
    doc["success"] = false;
    doc["error"]   = "SSID is required";
    String out;
    serializeJson(doc, out);
    server.send(400, "application/json", out);
    return;
  }

  if (!saveCredentials(ssid, pass)) {
    DynamicJsonDocument doc(128);
    doc["success"] = false;
    doc["error"]   = "Failed to save credentials";
    String out;
    serializeJson(doc, out);
    server.send(500, "application/json", out);
    return;
  }

  DynamicJsonDocument doc(128);
  doc["success"] = true;
  doc["message"] = "Credentials saved. Rebooting...";
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);

  // Schedule reboot after response is sent
  delay(500);
  ESP.restart();
}

void setupModeInit() {
  setupMode = true;
  Serial.println("=== SETUP MODE ===");
  Serial.printf("Starting AP: %s\n", SETUP_AP_SSID);

  WiFi.mode(WIFI_AP);
  WiFi.softAP(SETUP_AP_SSID, SETUP_AP_PASSWORD);

  IPAddress apIP = WiFi.softAPIP();
  Serial.print("AP IP: ");
  Serial.println(apIP);

  // Captive portal: redirect all requests to /config
  server.on("/",          HTTP_GET, handleSetupRoot);
  server.on("/config",    HTTP_GET, handleConfigPage);
  server.on("/scan",      HTTP_GET, handleScanWiFi);
  server.on("/save",      HTTP_POST, handleSaveCredentials);
  server.onNotFound([]() {
    server.sendHeader("Location", "/config", true);
    server.send(302, "text/plain", "Redirecting to setup");
  });

  server.begin();
  Serial.println("Captive portal started at http://192.168.4.1/config");
  Serial.println("Hold SETUP button again during boot to exit setup mode");

  // Blink LED rapidly to indicate setup mode
  while (true) {
    digitalWrite(PIN_LED, LOW);
    delay(100);
    digitalWrite(PIN_LED, HIGH);
    delay(100);
    server.handleClient();
  }
}

/* ============================================================
 * 10. NORMAL MODE — Vendo Operation Handlers
 * ============================================================ */

void handleNormalRoot() {
  server.send(200, "text/plain", "AIRCOINS NETFI Vendo OK");
}

void handleStatus() {
  DynamicJsonDocument doc(256);
  doc["mac"]       = WiFi.macAddress();
  doc["ip"]        = WiFi.localIP().toString();
  doc["ssid"]      = WiFi.SSID();
  doc["rssi"]      = WiFi.RSSI();
  doc["uptime_ms"] = millis();
  doc["connected"] = (WiFi.status() == WL_CONNECTED);
  doc["setup_mode"]= false;

  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handleDataFile() {
  String mac = server.arg(0);
  mac.toUpperCase();
  mac.replace(":", "");
  mac.replace("-", "");
  mac.replace(".", "");

  if (mac.length() != 12) {
    server.send(400, "text/plain", "invalid_mac");
    return;
  }

  String content = readDataFile(mac);
  if (content.length() == 0) {
    server.send(404, "text/plain", "no_voucher");
    return;
  }

  server.send(200, "text/plain", content);
}

void handleGetRates() {
  server.send(200, "text/plain", PROMO_RATES);
}

void handleCheckCoin() {
  String voucher = server.arg("voucher");

  noInterrupts();
  uint16_t newPulses = coinPulseCount;
  coinPulseCount = 0;
  interrupts();

  coinTotal += newPulses;

  if (newPulses == 0 && coinTotal == 0) {
    DynamicJsonDocument doc(256);
    doc["status"]    = "false";
    doc["errorCode"] = "coin.not.inserted";
    doc["totalCoin"] = 0;
    doc["remainTime"] = 30000;
    doc["waitTime"]   = 30000;
    doc["validity"]   = "10";
    doc["timeAdded"]  = "0";
    doc["data"]       = "0";
    String out;
    serializeJson(doc, out);
    server.send(200, "application/json", out);
    return;
  }

  if (newPulses > 0) {
    String code = generateVoucherCode();
    pendingVoucher = code;

    String mac = server.arg("mac");
    if (mac.length() > 0) {
      mac.toUpperCase();
      mac.replace(":", "");
      writeDataFile(mac, code);
      pendingMac = mac;
    }

    blinkLed(newPulses);

    DynamicJsonDocument doc(256);
    doc["status"]     = "true";
    doc["totalCoin"]  = coinTotal;
    doc["newCoin"]    = newPulses;
    doc["voucher"]    = code;
    doc["timeAdded"]  = String(newPulses * 600);
    doc["data"]       = String(newPulses * 100);
    String out;
    serializeJson(doc, out);
    server.send(200, "application/json", out);
    return;
  }

  DynamicJsonDocument doc(256);
  doc["status"]     = "true";
  doc["totalCoin"]  = coinTotal;
  doc["newCoin"]    = 0;
  doc["voucher"]    = pendingVoucher;
  doc["timeAdded"]  = String(coinTotal * 600);
  doc["data"]       = String(coinTotal * 100);
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handleGenerateVoucher() {
  String mac = server.arg("mac");
  mac.toUpperCase();
  mac.replace(":", "");
  mac.replace("-", "");
  mac.replace(".", "");

  if (mac.length() != 12) {
    DynamicJsonDocument doc(128);
    doc["status"]    = "false";
    doc["errorCode"] = "invalid_mac";
    String out;
    serializeJson(doc, out);
    server.send(400, "application/json", out);
    return;
  }

  String code = generateVoucherCode();
  writeDataFile(mac, code);
  pendingVoucher = code;
  pendingMac = mac;
  voucherGeneratedAtMs = millis();

  blinkLed(2);

  DynamicJsonDocument doc(128);
  doc["status"]  = "true";
  doc["voucher"] = code;
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handleCancelTopUp() {
  String mac = server.arg("mac");
  mac.toUpperCase();
  mac.replace(":", "");

  noInterrupts();
  coinPulseCount = 0;
  interrupts();
  coinTotal = 0;
  pendingVoucher = "";
  pendingMac = "";

  String path = "/data/" + mac + ".txt";
  if (SPIFFS.exists(path)) SPIFFS.remove(path);

  server.send(200, "text/plain", "cancelled");
}

void handleNotFound() {
  server.send(404, "text/plain", "not_found");
}

void normalModeInit() {
  setupMode = false;
  Serial.println("=== NORMAL MODE ===");
  Serial.printf("Connecting to '%s'...\n", savedSsid.c_str());

  WiFi.mode(WIFI_STA);
  WiFi.hostname("vendo-" + WiFi.macAddress().substring(12, 17).replace(":", ""));
  WiFi.begin(savedSsid.c_str(), savedPassword.c_str());

  uint8_t attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println();
    Serial.print("Connected!  IP: ");
    Serial.println(WiFi.localIP());
    blinkLed(3);
  } else {
    Serial.println(" FAILED — will retry in loop()");
  }

  // HTTP routes
  server.on("/",           HTTP_GET,  handleNormalRoot);
  server.on("/status",     HTTP_GET,  handleStatus);
  server.on("/getRates",   HTTP_GET,  handleGetRates);
  server.on("/data/(.+)",  HTTP_GET,  handleDataFile);
  server.on("/checkCoin",        HTTP_POST, handleCheckCoin);
  server.on("/generateVoucher",  HTTP_POST, handleGenerateVoucher);
  server.on("/cancelTopUp",      HTTP_POST, handleCancelTopUp);
  server.onNotFound(handleNotFound);

  server.begin();
  Serial.println("HTTP server started on port " + String(HTTP_PORT));
  Serial.println("=== Ready ===");
}

/* ============================================================
 * 11. setup()
 * ============================================================ */

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println();
  Serial.println("=== AIRCOINS NETFI Vendo Firmware v2 ===");

  // LED
  pinMode(PIN_LED, OUTPUT);
  digitalWrite(PIN_LED, HIGH);

  // Setup button (active-LOW, internal pullup)
  pinMode(PIN_SETUP_BTN, INPUT_PULLUP);

  // Coin acceptor pin
  pinMode(PIN_COIN, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(PIN_COIN), coinPulseISR, FALLING);

  // SPIFFS
  Serial.print("Mounting SPIFFS... ");
  if (!SPIFFS.begin()) {
    Serial.println("FAILED — formatting...");
    SPIFFS.format();
    SPIFFS.begin();
  }
  Serial.println("OK");

  if (!SPIFFS.exists("/data")) {
    SPIFFS.mkdir("/data");
    Serial.println("Created /data/ directory");
  }

  // Check if SETUP button is held during boot
  bool setupButtonHeld = (digitalRead(PIN_SETUP_BTN) == LOW);

  // Load saved credentials
  bool hasCredentials = loadCredentials();

  Serial.printf("Setup button: %s\n", setupButtonHeld ? "HELD" : "not held");
  Serial.printf("Saved credentials: %s\n", hasCredentials ? "YES" : "NO");

  // Enter setup mode if:
  //  - Setup button is held during boot, OR
  //  - No saved credentials exist (first boot)
  if (setupButtonHeld || !hasCredentials) {
    setupModeInit();  // This function never returns
  }

  // Normal mode — connect to saved WiFi
  normalModeInit();
}

/* ============================================================
 * 12. loop()
 * ============================================================ */

void loop() {
  server.handleClient();

  // Reconnect WiFi if dropped
  if (WiFi.status() != WL_CONNECTED) {
    static uint32_t lastReconnectMs = 0;
    if (millis() - lastReconnectMs > 5000) {
      lastReconnectMs = millis();
      Serial.print("WiFi lost — reconnecting... ");
      WiFi.reconnect();
      delay(5000);
      if (WiFi.status() == WL_CONNECTED) {
        Serial.println("OK");
        blinkLed(2);
      } else {
        Serial.println("FAILED");
      }
    }
  }

  // Periodic status (every 30 s)
  static uint32_t lastStatusMs = 0;
  if (millis() - lastStatusMs > 30000) {
    lastStatusMs = millis();
    Serial.printf("[status] IP=%s  coins=%u  voucher=%s  mac=%s\n",
      WiFi.localIP().toString().c_str(),
      coinTotal,
      pendingVoucher.c_str(),
      pendingMac.c_str()
    );
  }

  delay(10);
}
