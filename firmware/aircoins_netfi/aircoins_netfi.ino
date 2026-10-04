/*
 * ============================================================
 *  AIRCOINS NETFI — NodeMCU ESP8266 Vendo Firmware (v3)
 * ============================================================
 *
 *  Role:  Coin-operated WiFi vending — direct MAC-based session crediting.
 *
 *  When a coin is inserted, the NodeMCU calls the MikroTik REST API to
 *  create or extend a hotspot user keyed by the client's MAC address
 *  (uppercase, colon-free). No voucher codes are generated.
 *
 *  Two operating modes:
 *    1. SETUP MODE — Creates open AP "aircoins_coinslot_setup" with
 *       captive portal at /config for WiFi + router API configuration.
 *       Triggered by: holding SETUP button (GPIO D3) during boot, OR
 *       first boot (no saved credentials in SPIFFS).
 *
 *    2. NORMAL MODE — Connects to configured hotspot as STA,
 *       runs coin-slot vendo logic with direct router API integration.
 *
 *  Setup Portal (/config):
 *    - WiFi scan + select SSID + password -> saves to SPIFFS
 *    - Router API IP, port, username, password -> saves to SPIFFS
 *    - "Connect & Save" -> saves all credentials, reboots to STA mode
 *
 *  Normal Mode Endpoints:
 *    GET  /status          JSON: MAC, IP, uptime, connection state
 *    GET  /getRates        Promo rates (pipe-delimited)
 *    POST /insertCoin      Insert coin for MAC — calls router REST API
 *      Query param: ?mac=AABBCCDDEEFF (uppercase, no colons)
 *      Response: {"status":"true","coins":N,"time_added":"15m","mac":"..."}
 *
 *  Hardware:
 *    - NodeMCU ESP8266 (ESP-12E / ESP-12F)
 *    - Coin acceptor  ->  GPIO D2 (pin 4), active-LOW pulse
 *    - Setup button   ->  GPIO D3 (pin 0), active-LOW (built-in pullup)
 *    - Status LED     ->  GPIO D4 (pin 2, built-in LED, active-LOW)
 *
 *  Dependencies (Arduino IDE Board Manager):
 *    - esp8266 by ESP8266 Community  (v3.x)
 *    Libraries (ALL built-in, NO extra installs):
 *    - ESP8266WiFi
 *    - ESP8266WebServer
 *    - ESP8266HTTPClient
 *    - SPIFFS
 *
 *  Upload:
 *    Board:  "NodeMCU 1.0 (ESP-12E Module)"
 *    Flash:  4M (3M SPIFFS)
 *    Port:   your COM port
 * ============================================================
 */

#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <FS.h>           // SPIFFS (built-in)

/* ============================================================
 * 1. CONFIGURATION
 * ============================================================ */

// Setup mode
static const char* SETUP_AP_SSID     = "aircoins_coinslot_setup";
static const char* SETUP_AP_PASSWORD = "";  // empty = open AP
static const uint8_t PIN_SETUP_BTN   = 0;   // GPIO D3 / flash button (active-LOW)

// Pin definitions
static const uint8_t PIN_COIN        = 4;   // GPIO D2 - coin acceptor
static const uint8_t PIN_LED         = 2;   // GPIO D4 - status LED (active-LOW)

// Coin pulse debounce (ms)
static const uint16_t COIN_DEBOUNCE_MS = 150;

// Minutes of internet time granted per coin pulse
static const uint16_t MINUTES_PER_PULSE = 15;

// HTTP server port
static const uint16_t HTTP_PORT = 80;

// /insertCoin polling timeout (ms) — how long to wait for a coin
static const uint32_t INSERT_COIN_TIMEOUT_MS = 30000;

// SPIFFS paths
// WiFi credentials: line 1 = SSID, line 2 = password
static const char* CRED_FILE = "/wifi_cred.txt";
// Router API credentials: line 1 = IP, line 2 = port, line 3 = username, line 4 = password
static const char* ROUTER_API_FILE = "/router_api.txt";

/* ============================================================
 * 2. GLOBAL STATE
 * ============================================================ */

ESP8266WebServer  server(HTTP_PORT);

// Operating mode
bool setupMode = false;

// WiFi credentials (loaded from SPIFFS)
String savedSsid     = "";
String savedPassword = "";

// Router REST API credentials (loaded from SPIFFS)
String routerApiIp   = "";
int    routerApiPort = 80;
String routerApiUser = "";
String routerApiPass = "";

// Coin acceptor state
volatile uint16_t coinPulseCount   = 0;
uint32_t          lastPulseMs      = 0;

// Promo rates — built at runtime from MINUTES_PER_PULSE
// Format per line: coins#name#label#minutes#data_mb  (lines separated by \n)
String promoRatesStr = "";

// Last router API result message (for debug)
String lastRouterResult = "";

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
void handleGetRates();
void handleInsertCoin();
void handleNotFound();

bool routerCreateOrExtendMacUser(const String& macNoColons, uint32_t addSeconds, String& resultMsg);
String routerApiRequest(const String& method, const String& path, const String& body);

bool loadCredentials();
bool saveCredentials(const String& ssid, const String& password);
bool loadRouterApiConfig();
bool saveRouterApiConfig(const String& ip, int port, const String& user, const String& pass);

void blinkLed(uint8_t times);
String jsonEscape(const String& raw);
String parseTimeToSeconds(const String& timeStr);

/* ============================================================
 * 4. JSON HELPER (no ArduinoJson dependency)
 * ============================================================ */

String jsonEscape(const String& raw) {
  String out;
  out.reserve(raw.length() + 8);
  for (unsigned int i = 0; i < raw.length(); i++) {
    char c = raw.charAt(i);
    switch (c) {
      case '"':  out += "\\\""; break;
      case '\\': out += "\\\\"; break;
      case '\n': out += "\\n";  break;
      case '\r': out += "\\r";  break;
      case '\t': out += "\\t";  break;
      default:   out += c;      break;
    }
  }
  return out;
}

/* ============================================================
 * 5. CREDENTIAL STORAGE (SPIFFS)
 * ============================================================ */

bool loadCredentials() {
  if (!SPIFFS.exists(CRED_FILE)) return false;
  File f = SPIFFS.open(CRED_FILE, "r");
  if (!f) return false;
  savedSsid     = f.readStringUntil('\n');
  savedPassword = f.readStringUntil('\n');
  f.close();
  savedSsid.trim();
  savedPassword.trim();
  return savedSsid.length() > 0;
}

bool saveCredentials(const String& ssid, const String& password) {
  File f = SPIFFS.open(CRED_FILE, "w");
  if (!f) return false;
  f.println(ssid);
  f.println(password);
  f.close();
  return true;
}

bool loadRouterApiConfig() {
  if (!SPIFFS.exists(ROUTER_API_FILE)) return false;
  File f = SPIFFS.open(ROUTER_API_FILE, "r");
  if (!f) return false;
  routerApiIp   = f.readStringUntil('\n'); routerApiIp.trim();
  String portStr = f.readStringUntil('\n'); portStr.trim();
  routerApiUser = f.readStringUntil('\n'); routerApiUser.trim();
  routerApiPass = f.readStringUntil('\n'); routerApiPass.trim();
  f.close();
  routerApiPort = portStr.toInt();
  if (routerApiPort <= 0) routerApiPort = 80;
  return routerApiIp.length() > 0;
}

bool saveRouterApiConfig(const String& ip, int port, const String& user, const String& pass) {
  File f = SPIFFS.open(ROUTER_API_FILE, "w");
  if (!f) return false;
  f.println(ip);
  f.println(String(port));
  f.println(user);
  f.println(pass);
  f.close();
  return true;
}

/* ============================================================
 * 6. COIN ACCEPTOR INTERRUPT
 * ============================================================ */

ICACHE_RAM_ATTR void coinPulseISR() {
  uint32_t now = millis();
  if (now - lastPulseMs < COIN_DEBOUNCE_MS) return;
  lastPulseMs = now;
  coinPulseCount++;
}

/* ============================================================
 * 7. LED HELPER
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
 * 8. MIKROTIK TIME PARSER
 * ============================================================ */

/**
 * Parse MikroTik time string (e.g. "1h30m", "15m", "1d") to seconds.
 */
String parseTimeToSeconds(const String& timeStr) {
  if (timeStr.length() == 0) return "0";
  uint32_t total = 0;
  uint32_t current = 0;
  for (unsigned int i = 0; i < timeStr.length(); i++) {
    char c = timeStr.charAt(i);
    if (c >= '0' && c <= '9') {
      current = current * 10 + (c - '0');
    } else if (c == 'd') {
      total += current * 86400; current = 0;
    } else if (c == 'h') {
      total += current * 3600; current = 0;
    } else if (c == 'm') {
      total += current * 60; current = 0;
    } else if (c == 's') {
      total += current; current = 0;
    } else if (c == 'w') {
      total += current * 604800; current = 0;
    }
  }
  total += current; // remaining seconds
  return String(total);
}

/* ============================================================
 * 9. ROUTER REST API CLIENT
 * ============================================================ */

/**
 * Make an HTTP request to the MikroTik REST API.
 * Returns the response body as a string, or empty on failure.
 */
String routerApiRequest(const String& method, const String& path, const String& body) {
  if (routerApiIp.length() == 0) {
    lastRouterResult = "Router API not configured";
    return "";
  }

  String url;
  if (routerApiPort == 443) {
    url = "https://" + routerApiIp + ":" + String(routerApiPort) + path;
  } else {
    url = "http://" + routerApiIp + ":" + String(routerApiPort) + path;
  }

  HTTPClient http;
  WiFiClient client;
  WiFiClientSecure clientSecure;

  if (routerApiPort == 443) {
    clientSecure.setInsecure();  // Skip cert verification (self-signed)
    http.begin(clientSecure, url);
  } else {
    http.begin(client, url);
  }

  // Basic auth
  if (routerApiUser.length() > 0) {
    http.setAuthorization(routerApiUser.c_str(), routerApiPass.c_str());
  }

  http.setTimeout(10000);  // 10s timeout

  if (method == "GET") {
    http.GET();
  } else if (method == "PUT") {
    http.addHeader("Content-Type", "application/json");
    http.PUT(body);
  } else if (method == "PATCH") {
    http.addHeader("Content-Type", "application/json");
    http.PATCH(body);
  } else if (method == "DELETE") {
    http.DELETE();
  }

  int httpCode = http.getCode();
  String response = http.getString();
  http.end();

  if (httpCode < 200 || httpCode >= 300) {
    lastRouterResult = "HTTP " + String(httpCode) + ": " + response;
    Serial.printf("[router API] %s %s -> %d\n", method.c_str(), path.c_str(), httpCode);
    Serial.println(response);
    return "";
  }

  return response;
}

/**
 * Simple JSON string value extractor.
 * Finds "key":"value" or "key":value in a JSON string.
 */
String jsonGetString(const String& json, const String& key) {
  String searchKey = "\"" + key + "\":";
  int idx = json.indexOf(searchKey);
  if (idx < 0) {
    // Try with space: "key" : "value"
    searchKey = "\"" + key + "\" : ";
    idx = json.indexOf(searchKey);
    if (idx < 0) return "";
  }
  int valStart = idx + searchKey.length();
  // Skip whitespace
  while (valStart < (int)json.length() && json.charAt(valStart) == ' ') valStart++;
  if (valStart >= (int)json.length()) return "";

  if (json.charAt(valStart) == '"') {
    // Quoted string value
    valStart++;
    int valEnd = json.indexOf('"', valStart);
    if (valEnd < 0) return "";
    return json.substring(valStart, valEnd);
  } else {
    // Unquoted value (number, bool, etc.)
    int valEnd = valStart;
    while (valEnd < (int)json.length() && json.charAt(valEnd) != ',' && json.charAt(valEnd) != '}' && json.charAt(valEnd) != ']') {
      valEnd++;
    }
    return json.substring(valStart, valEnd);
  }
}

/**
 * Create or extend a hotspot user on the MikroTik router.
 * The user is keyed by MAC (uppercase, no colons) with password = MAC.
 * If the user already exists, the limit-uptime is EXTENDED by addSeconds.
 * If not, a new user is created.
 *
 * Returns true on success.
 */
bool routerCreateOrExtendMacUser(const String& macNoColons, uint32_t addSeconds, String& resultMsg) {
  String addTimeStr = String(addSeconds) + "s";

  // Step 1: Check if user already exists
  String listResponse = routerApiRequest("GET",
    "/rest/ip/hotspot/user?name=" + macNoColons, "");

  if (listResponse.length() > 2) {
    // User exists — listResponse is a JSON array like [{"name":"...","limit-uptime":"...",".id":"*X"}]
    // Find the .id and current limit-uptime
    String userId = jsonGetString(listResponse, ".id");
    String currentLimit = jsonGetString(listResponse, "limit-uptime");

    if (userId.length() > 0) {
      // Parse current limit to seconds, add new time
      uint32_t currentSec = parseTimeToSeconds(currentLimit).toInt();
      uint32_t newTotal = currentSec + addSeconds;
      String newLimitStr = String(newTotal) + "s";

      // PATCH to update limit-uptime
      String patchBody = "{\"limit-uptime\":\"" + newLimitStr + "\"}";
      String patchResp = routerApiRequest("PATCH",
        "/rest/ip/hotspot/user/" + userId, patchBody);

      if (patchResp.length() >= 0) {
        resultMsg = "Extended by " + addTimeStr + " (total: " + String(newTotal) + "s)";
        Serial.printf("[router] Extended user %s: %s -> %s\n",
          macNoColons.c_str(), currentLimit.c_str(), newLimitStr.c_str());
        return true;
      } else {
        resultMsg = "PATCH failed: " + lastRouterResult;
        return false;
      }
    }
  }

  // Step 2: User does not exist — create new
  // Build JSON body manually (no ArduinoJson)
  String createBody = "{";
  createBody += "\"name\":\"" + jsonEscape(macNoColons) + "\",";
  createBody += "\"password\":\"" + jsonEscape(macNoColons) + "\",";
  createBody += "\"limit-uptime\":\"" + addTimeStr + "\",";
  createBody += "\"comment\":\"vendo-coin\"";
  createBody += "}";

  String createResp = routerApiRequest("PUT",
    "/rest/ip/hotspot/user", createBody);

  if (createResp.length() >= 0) {
    resultMsg = "Created with " + addTimeStr;
    Serial.printf("[router] Created user %s with limit %s\n",
      macNoColons.c_str(), addTimeStr.c_str());
    return true;
  } else {
    resultMsg = "CREATE failed: " + lastRouterResult;
    return false;
  }
}

/* ============================================================
 * 10. SETUP MODE — Captive Portal Handlers
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
  <title>AIRCOINS NETFI - Vendo Setup</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:600px;margin:40px auto;padding:20px;background:#f5f5f5}
    h1{color:#2c3e50;text-align:center}
    .card{background:#fff;border-radius:8px;padding:20px;box-shadow:0 2px 4px rgba(0,0,0,.1);margin-bottom:20px}
    label{display:block;margin:10px 0 5px;font-weight:700;color:#34495e}
    select,input{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box}
    button{width:100%;padding:12px;background:#3498db;color:#fff;border:none;border-radius:4px;font-size:16px;cursor:pointer;margin-top:15px}
    button:hover{background:#2980b9}
    button:disabled{background:#95a5a6;cursor:not-allowed}
    .status{padding:10px;border-radius:4px;margin-top:15px;text-align:center}
    .status.info{background:#d1ecf1;color:#0c5460}
    .status.success{background:#d4edda;color:#155724}
    .status.error{background:#f8d7da;color:#721c24}
    .spinner{display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:8px;vertical-align:middle}
    @keyframes spin{to{transform:rotate(360deg)}}
    h2{color:#2c3e50;font-size:16px;border-bottom:2px solid #3498db;padding-bottom:8px;margin-top:0}
  </style>
</head>
<body>
  <h1>AIRCOINS NETFI &mdash; Vendo Setup</h1>

  <div class='card'>
    <h2>1. Hotspot WiFi Connection</h2>
    <label>WiFi Network</label>
    <button id='scanBtn' onclick='scanWiFi()'>Scan WiFi Networks</button>
    <div id='scanStatus' class='status info' style='display:none;margin-top:10px'></div>
    <select id='ssidSelect' style='margin-top:10px'>
      <option value=''>-- Scan or enter manually --</option>
    </select>
    <label>WiFi Password</label>
    <input type='password' id='wifiPass' placeholder='Enter hotspot password (leave blank if open)'>
  </div>

  <div class='card'>
    <h2>2. MikroTik Router API</h2>
    <label>Router IP Address</label>
    <input type='text' id='routerIp' placeholder='e.g. 192.168.88.1' value='192.168.88.1'>
    <label>Router API Port</label>
    <input type='number' id='routerPort' placeholder='80 for HTTP, 443 for HTTPS' value='80'>
    <label>Router Username</label>
    <input type='text' id='routerUser' placeholder='e.g. admin' value='admin'>
    <label>Router Password</label>
    <input type='password' id='routerPass' placeholder='Enter router admin password'>
  </div>

  <div class='card'>
    <button id='saveBtn' onclick='saveConfig()'>Connect &amp; Save All</button>
    <div id='saveStatus' class='status info' style='display:none;margin-top:15px'></div>
  </div>

  <script>
    function scanWiFi(){
      var b=document.getElementById('scanBtn'),s=document.getElementById('scanStatus');
      b.disabled=true;s.style.display='block';s.className='status info';
      s.innerHTML='<span class="spinner"></span> Scanning for WiFi networks...';
      fetch('/scan').then(function(r){return r.json()}).then(function(d){
        b.disabled=false;
        if(d.error){s.className='status error';s.textContent='Scan failed: '+d.error;return}
        var sel=document.getElementById('ssidSelect');
        sel.innerHTML='<option value="">-- Select SSID --</option>';
        d.networks.forEach(function(n){
          var o=document.createElement('option');
          o.value=n.ssid;o.textContent=n.ssid+' ('+n.rssi+' dBm)'+(n.enc?' [locked]':'');
          sel.appendChild(o);
        });
        s.className='status success';s.textContent='Found '+d.networks.length+' networks';
      }).catch(function(e){b.disabled=false;s.className='status error';s.textContent='Scan failed: '+e});
    }
    function saveConfig(){
      var ssid=document.getElementById('ssidSelect').value,
          wifiPass=document.getElementById('wifiPass').value,
          rIp=document.getElementById('routerIp').value.trim(),
          rPort=document.getElementById('routerPort').value.trim(),
          rUser=document.getElementById('routerUser').value.trim(),
          rPass=document.getElementById('routerPass').value,
          b=document.getElementById('saveBtn'),
          s=document.getElementById('saveStatus');
      if(!ssid){s.style.display='block';s.className='status error';s.textContent='Please select a WiFi network';return}
      if(!rIp){s.style.display='block';s.className='status error';s.textContent='Please enter the router IP address';return}
      if(!rUser){s.style.display='block';s.className='status error';s.textContent='Please enter the router username';return}
      b.disabled=true;s.style.display='block';s.className='status info';
      s.innerHTML='<span class="spinner"></span> Saving credentials and connecting...';
      fetch('/save',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ssid='+encodeURIComponent(ssid)+'&wifi_pass='+encodeURIComponent(wifiPass)
          +'&router_ip='+encodeURIComponent(rIp)+'&router_port='+encodeURIComponent(rPort)
          +'&router_user='+encodeURIComponent(rUser)+'&router_pass='+encodeURIComponent(rPass)
      }).then(function(r){return r.json()}).then(function(d){
        if(d.success){s.className='status success';s.textContent='All saved! Rebooting to connect to '+ssid+'...';
          setTimeout(function(){alert('Device is rebooting. Close this page.')},1500);
        }else{s.className='status error';s.textContent='Save failed: '+(d.error||'unknown');b.disabled=false}
      }).catch(function(e){s.className='status error';s.textContent='Save failed: '+e;b.disabled=false});
    }
  </script>
</body>
</html>
)rawliteral";

  server.send(200, "text/html", html);
}

/**
 * GET /scan — scan WiFi networks and return JSON.
 */
void handleScanWiFi() {
  int n = WiFi.scanNetworks();
  if (n < 0) {
    server.send(500, "application/json", "{\"error\":\"WiFi scan failed\"}");
    return;
  }

  String json = "{\"networks\":[";
  for (int i = 0; i < n; i++) {
    if (i > 0) json += ",";
    json += "{\"ssid\":\"";
    json += jsonEscape(WiFi.SSID(i));
    json += "\",\"rssi\":";
    json += String(WiFi.RSSI(i));
    json += ",\"enc\":";
    json += (WiFi.encryptionType(i) != ENC_TYPE_NONE) ? "true" : "false";
    json += "}";
  }
  json += "]}";

  server.send(200, "application/json", json);
}

/**
 * POST /save — save WiFi + router API credentials and reboot.
 */
void handleSaveCredentials() {
  String ssid     = server.arg("ssid");
  String wifiPass = server.arg("wifi_pass");
  String rIp      = server.arg("router_ip");
  String rPortStr = server.arg("router_port");
  String rUser    = server.arg("router_user");
  String rPass    = server.arg("router_pass");

  if (ssid.length() == 0) {
    server.send(400, "application/json", "{\"success\":false,\"error\":\"SSID is required\"}");
    return;
  }
  if (rIp.length() == 0) {
    server.send(400, "application/json", "{\"success\":false,\"error\":\"Router IP is required\"}");
    return;
  }

  // Save WiFi credentials
  if (!saveCredentials(ssid, wifiPass)) {
    server.send(500, "application/json", "{\"success\":false,\"error\":\"Failed to save WiFi credentials\"}");
    return;
  }

  // Save router API credentials
  int rPort = rPortStr.toInt();
  if (rPort <= 0) rPort = 80;
  if (!saveRouterApiConfig(rIp, rPort, rUser, rPass)) {
    server.send(500, "application/json", "{\"success\":false,\"error\":\"Failed to save router API config\"}");
    return;
  }

  server.send(200, "application/json", "{\"success\":true,\"message\":\"All credentials saved. Rebooting...\"}");

  delay(500);
  ESP.restart();
}

/**
 * Enter setup mode — starts open AP and captive portal, never returns.
 */
void setupModeInit() {
  setupMode = true;
  Serial.println("=== SETUP MODE ===");
  Serial.printf("Starting AP: %s\n", SETUP_AP_SSID);

  WiFi.mode(WIFI_AP);
  WiFi.softAP(SETUP_AP_SSID, SETUP_AP_PASSWORD);

  IPAddress apIP = WiFi.softAPIP();
  Serial.print("AP IP: ");
  Serial.println(apIP);

  server.on("/",          HTTP_GET, handleSetupRoot);
  server.on("/config",    HTTP_GET, handleConfigPage);
  server.on("/scan",      HTTP_GET, handleScanWiFi);
  server.on("/save",      HTTP_POST, handleSaveCredentials);
  server.onNotFound([]() {
    server.sendHeader("Location", "/config", true);
    server.send(302, "text/plain", "Redirecting to setup");
  });

  server.begin();
  Serial.println("Setup portal started at http://192.168.4.1/config");

  // Blink LED rapidly to indicate setup mode — loop forever
  while (true) {
    digitalWrite(PIN_LED, LOW);
    delay(100);
    digitalWrite(PIN_LED, HIGH);
    delay(100);
    server.handleClient();
  }
}

/* ============================================================
 * 11. NORMAL MODE — Vendo Operation Handlers
 * ============================================================ */

void handleNormalRoot() {
  server.send(200, "text/plain", "AIRCOINS NETFI Vendo v3 OK");
}

/**
 * GET /status — JSON health check.
 */
void handleStatus() {
  String json = "{";
  json += "\"mac\":\""        + jsonEscape(WiFi.macAddress()) + "\",";
  json += "\"ip\":\""         + jsonEscape(WiFi.localIP().toString()) + "\",";
  json += "\"ssid\":\""       + jsonEscape(WiFi.SSID()) + "\",";
  json += "\"rssi\":"         + String(WiFi.RSSI()) + ",";
  json += "\"uptime_ms\":"    + String(millis()) + ",";
  json += "\"connected\":"    + String((WiFi.status() == WL_CONNECTED) ? "true" : "false") + ",";
  json += "\"setup_mode\":false,";
  json += "\"router_api\":\"" + (routerApiIp.length() > 0 ? jsonEscape(routerApiIp) : "") + "\"";
  json += "}";
  server.send(200, "application/json", json);
}

void handleGetRates() {
  server.send(200, "text/plain", promoRatesStr);
}

/**
 * POST /insertCoin?mac=AABBCCDDEEFF
 *
 * Waits for coin pulse(s), then calls the MikroTik REST API to create
 * or extend a hotspot user keyed by the client MAC.
 *
 * Response:
 *   {"status":"true","coins":N,"time_added":"15m","mac":"AABBCCDDEEFF"}
 *   {"status":"false","error":"no_coin"}
 *   {"status":"false","error":"router_api_failed","detail":"..."}
 */
void handleInsertCoin() {
  // Read and normalize MAC
  String mac = server.arg("mac");
  mac.toUpperCase();
  mac.replace(":", "");
  mac.replace("-", "");
  mac.replace(".", "");

  if (mac.length() != 12) {
    server.send(400, "application/json",
      "{\"status\":\"false\",\"error\":\"invalid_mac\",\"detail\":\"MAC must be 12 hex chars\"}");
    return;
  }

  if (routerApiIp.length() == 0) {
    server.send(500, "application/json",
      "{\"status\":\"false\",\"error\":\"router_not_configured\"}");
    return;
  }

  // Wait for coin pulse(s) within timeout
  Serial.printf("[insertCoin] Waiting for coin from MAC %s...\n", mac.c_str());

  uint32_t startMs = millis();
  uint16_t pulses = 0;

  while (millis() - startMs < INSERT_COIN_TIMEOUT_MS) {
    noInterrupts();
    pulses = coinPulseCount;
    coinPulseCount = 0;
    interrupts();

    if (pulses > 0) break;
    delay(50);
    server.handleClient();  // Keep HTTP alive while waiting
  }

  if (pulses == 0) {
    server.send(200, "application/json",
      "{\"status\":\"false\",\"error\":\"no_coin\",\"detail\":\"No coin inserted within timeout\"}");
    return;
  }

  // Calculate time to add
  uint32_t addSeconds = (uint32_t)pulses * MINUTES_PER_PULSE * 60;
  Serial.printf("[insertCoin] %u pulse(s) from MAC %s -> %u seconds\n",
    pulses, mac.c_str(), addSeconds);

  // Call router REST API
  String resultMsg = "";
  bool ok = routerCreateOrExtendMacUser(mac, addSeconds, resultMsg);

  if (ok) {
    blinkLed(pulses);

    // Format time for display
    uint32_t addMins = addSeconds / 60;
    String timeAdded = String(addMins) + "m";

    String json = "{";
    json += "\"status\":\"true\",";
    json += "\"coins\":" + String(pulses) + ",";
    json += "\"time_added\":\"" + timeAdded + "\",";
    json += "\"mac\":\"" + jsonEscape(mac) + "\",";
    json += "\"detail\":\"" + jsonEscape(resultMsg) + "\"";
    json += "}";
    server.send(200, "application/json", json);
  } else {
    String json = "{";
    json += "\"status\":\"false\",";
    json += "\"error\":\"router_api_failed\",";
    json += "\"detail\":\"" + jsonEscape(resultMsg) + "\"";
    json += "}";
    server.send(200, "application/json", json);
  }
}

void handleNotFound() {
  server.send(404, "text/plain", "not_found");
}

/**
 * Normal mode init — connect to saved WiFi as STA, start HTTP server.
 */
void normalModeInit() {
  setupMode = false;
  Serial.println("=== NORMAL MODE ===");
  Serial.printf("Connecting to '%s'...\n", savedSsid.c_str());

  WiFi.mode(WIFI_STA);
  String hostname = "vendo-" + WiFi.macAddress().substring(12, 17);
  hostname.replace(":", "");
  WiFi.hostname(hostname.c_str());
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
    Serial.println(" FAILED - will retry in loop()");
  }

  // HTTP routes
  server.on("/",           HTTP_GET,  handleNormalRoot);
  server.on("/status",     HTTP_GET,  handleStatus);
  server.on("/getRates",   HTTP_GET,  handleGetRates);
  server.on("/insertCoin", HTTP_POST, handleInsertCoin);
  server.onNotFound(handleNotFound);

  server.begin();
  Serial.println("HTTP server started on port " + String(HTTP_PORT));
  Serial.printf("Router API: %s:%d (user: %s)\n",
    routerApiIp.c_str(), routerApiPort, routerApiUser.c_str());
  Serial.println("=== Ready ===");
}

/* ============================================================
 * 12. setup()
 * ============================================================ */

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println();
  Serial.println("=== AIRCOINS NETFI Vendo Firmware v3 ===");

  // Build promo rates string from MINUTES_PER_PULSE
  promoRatesStr = "";
  promoRatesStr += "1 Coin###" + String(MINUTES_PER_PULSE) + "#";
  promoRatesStr += "|3 Coins###" + String(MINUTES_PER_PULSE * 3) + "#";
  promoRatesStr += "|5 Coins###" + String(MINUTES_PER_PULSE * 5) + "#";
  promoRatesStr += "|10 Coins###" + String(MINUTES_PER_PULSE * 10) + "#";

  // LED
  pinMode(PIN_LED, OUTPUT);
  digitalWrite(PIN_LED, HIGH);  // OFF (active-LOW)

  // Setup button (active-LOW, internal pullup)
  pinMode(PIN_SETUP_BTN, INPUT_PULLUP);

  // Coin acceptor pin
  pinMode(PIN_COIN, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(PIN_COIN), coinPulseISR, FALLING);

  // SPIFFS
  Serial.print("Mounting SPIFFS... ");
  if (!SPIFFS.begin()) {
    Serial.println("FAILED - formatting...");
    SPIFFS.format();
    SPIFFS.begin();
  }
  Serial.println("OK");

  // Check if SETUP button is held during boot
  bool setupButtonHeld = (digitalRead(PIN_SETUP_BTN) == LOW);

  // Load saved credentials
  bool hasWifiCred = loadCredentials();
  bool hasRouterApi = loadRouterApiConfig();

  Serial.printf("Setup button: %s\n", setupButtonHeld ? "HELD" : "not held");
  Serial.printf("WiFi credentials: %s\n", hasWifiCred ? "YES" : "NO");
  Serial.printf("Router API config: %s\n", hasRouterApi ? "YES" : "NO");

  // Enter setup mode if:
  //  - Setup button is held during boot, OR
  //  - No saved WiFi credentials (first boot)
  if (setupButtonHeld || !hasWifiCred) {
    setupModeInit();  // This function never returns
  }

  // Normal mode - connect to saved WiFi
  normalModeInit();
}

/* ============================================================
 * 13. loop()
 * ============================================================ */

void loop() {
  server.handleClient();

  // Reconnect WiFi if dropped
  if (WiFi.status() != WL_CONNECTED) {
    static uint32_t lastReconnectMs = 0;
    if (millis() - lastReconnectMs > 5000) {
      lastReconnectMs = millis();
      Serial.print("WiFi lost - reconnecting... ");
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
    Serial.printf("[status] IP=%s  router=%s:%d  lastResult=%s\n",
      WiFi.localIP().toString().c_str(),
      routerApiIp.c_str(),
      routerApiPort,
      lastRouterResult.c_str()
    );
  }

  delay(10);
}
