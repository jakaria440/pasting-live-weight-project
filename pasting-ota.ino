#include <WiFi.h>
#include <HTTPClient.h>
#include <HardwareSerial.h>
#include <WiFiManager.h>
#include <ArduinoOTA.h>

// ---------------- SETTINGS ----------------
const char* machine_no = "2";
const char* server_ip = "192.168.1.2:50001"; // Verify this port is open!

// ---------------- PINS ----------------
#define RX_PIN 16    // Connect to Max3232 TX
#define TX_PIN 17    // Not used for receiving, but defined
#define LED_WIFI 2
#define LED_DATA 15

// ---------------- GLOBALS ----------------
HardwareSerial scaleSerial(1);
String serverUrl;
float lastWeight = 0;
bool weightSent = false;
unsigned long stabilityTimer = 0;

void setup() {
  Serial.begin(115200);
  
  // AND EKI series usually defaults to 2400 or 9600. Try 2400 if 9600 fails.
  scaleSerial.begin(9600, SERIAL_8N1, RX_PIN, TX_PIN);

  pinMode(LED_WIFI, OUTPUT);
  pinMode(LED_DATA, OUTPUT);

  WiFiManager wm;
  // wm.resetSettings(); // Uncomment this once to clear old WiFi if needed
  if (!wm.autoConnect("Scale_Machine_2")) {
    Serial.println("Failed to connect, restarting...");
    delay(3000);
    ESP.restart();
  }

  serverUrl = "http://" + String(server_ip) + "/pasting-weight/api.php";
  
  ArduinoOTA.setHostname("Scale-Machine-2");
  ArduinoOTA.begin();

  digitalWrite(LED_WIFI, HIGH);
  Serial.println("--- Scale 2 Ready ---");
}

// Robust parsing for AND Format: "ST, +0001.23  g"
float extractWeight(String raw) {
  raw.trim();
  if (raw.length() == 0) return -1.0;

  String numericPart = "";
  bool started = false;

  for (int i = 0; i < raw.length(); i++) {
    char c = raw.charAt(i);
    if (isdigit(c) || c == '.' || c == '-') {
      numericPart += c;
      started = true;
    } else if (started && c == ' ') {
      break; 
    }
  }
  return (numericPart.length() > 0) ? numericPart.toFloat() : -1.0;
}

void sendToDatabase(float weight) {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(serverUrl);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  // Format exactly as your PHP expects
  String postData = "machine_no=" + String(machine_no) +
                    "&weight=" + String(weight, 2) +
                    "&item_id=0"; // Defaulting item_id to 0 for now

  int httpCode = http.POST(postData);
  
  if (httpCode > 0) {
    String response = http.getString();
    Serial.print("Server Response: ");
    Serial.println(response); // CHECK SERIAL MONITOR FOR PHP ERRORS HERE
    
    if (httpCode == 200) {
      digitalWrite(LED_DATA, HIGH);
      delay(500);
      digitalWrite(LED_DATA, LOW);
      weightSent = true; 
    }
  } else {
    Serial.printf("Error on POST: %s\n", http.errorToString(httpCode).c_str());
  }
  http.end();
}

void loop() {
  ArduinoOTA.handle();

  if (scaleSerial.available()) {
    String rawData = scaleSerial.readStringUntil('\n');
    float currentWeight = extractWeight(rawData);

    if (currentWeight > 0.1) { // Threshold to avoid noise
      // Check for stability (weight stays similar for 500ms)
      if (abs(currentWeight - lastWeight) < 0.05) {
        if (stabilityTimer == 0) stabilityTimer = millis();
        
        if (millis() - stabilityTimer > 500 && !weightSent) {
          sendToDatabase(currentWeight);
        }
      } else {
        stabilityTimer = millis();
        weightSent = false; // Reset sent flag because weight moved
      }
      lastWeight = currentWeight;
    } else {
      // Weight removed
      weightSent = false;
      stabilityTimer = 0;
      lastWeight = 0;
    }
  }
}