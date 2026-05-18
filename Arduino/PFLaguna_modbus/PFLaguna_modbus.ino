#include <ModbusMaster.h>
#include <Ethernet.h>
#include <SPI.h>
#include <avr/pgmspace.h>

#define MAX485_RE_NEG 3
#define MAX485_DE 2
#define ALARMPIN 22

unsigned long lastREAD = 0;
#define READ 10000

/***** Configurações Ethernet *****/
byte mac[] = { 0xDE, 0xAD, 0xBE, 0xEF, 0xFE, 0xE7 };
IPAddress ip(191, 188, 127, 30);
EthernetServer server(80);

/***** Variaveis a obter *****/
float freeChlorine;
float pH;
float temperature;
float C1Value;
float C1Process;
float C1Disturbance;
float C1SetPoint;
float C2Value;
float C2Process;
float C2Disturbance;
float C2SetPoint;
int alarm;

float analog_val4;
float analog_val5;
float analog_val6;
float analog_val7;

ModbusMaster node;

void preTransmission() {
  digitalWrite(MAX485_RE_NEG, 1);
  digitalWrite(MAX485_DE, 1);
}

void postTransmission() {
  digitalWrite(MAX485_RE_NEG, 0);
  digitalWrite(MAX485_DE, 0);
}

// Header 200 OK para XML
void HtmlHeaderOK_XML(EthernetClient client) {
  String header200 = "HTTP/1.1 200 OK\nContent-Type: text/xml\nConnection: keep-alive\nAccess-Control-Allow-Origin: *\nCache-Control: no-cache\nCache-Control: no-store";
  client.println(header200);
  client.println();
}

// Header para File Not Found
void HtmlHeader404(EthernetClient client) {
  String header404 = "HTTP/1.1 404 Not Found\nContent-Type: text/html\n\n<h2>File Not Found!</h2>";
  client.println(header404);
  client.println();
}


// Função que devolve o conteudo XML COM MODBUS
void XML_response(EthernetClient client)
{
  String xml_parameters = "<?xml version = \"1.0\" ?><inputs><poolName>Laguna</poolName><freeChlorine>" + String(freeChlorine, 2) + "</freeChlorine><pH>" + String(pH, 2) + "</pH><temperature>" + String(temperature, 1) + "</temperature><alarme>" + String(alarm) + "</alarme><C1Value>" + String(C1Value, 2) + "</C1Value><C1Process>" + String(C1Process, 2) + "</C1Process><C1Disturbance>" + String(C1Disturbance, 2) + "</C1Disturbance><C1SetPoint>" + String(C1SetPoint, 2) + "</C1SetPoint><C2Value>" + String(C2Value, 2) + "</C2Value><C2Process>" + String(C2Process, 2) + "</C2Process><C2Disturbance>" + String(C2Disturbance, 2) + "</C2Disturbance><C2SetPoint>" + String(C2SetPoint, 2) + "</C2SetPoint><analog4>" + String(analog_val4) + "</analog4><analog5>" + String(analog_val5) + "</analog5><analog6>" + String(analog_val6) + "</analog6><analog7>" + String(analog_val7) + "</analog7></inputs>";
  client.print(xml_parameters);
}

// Função de conexão Ethernet
#define BUFSIZE 75
void Connection() {
  char clientline[BUFSIZE];
  int index = 0;

  EthernetClient client = server.available();
  if (client) {
    index = 0;
    boolean isPost = false; // Flag to indicate if it's a POST request

    while (client.connected()) {
      if (client.available()) {
        char c = client.read();

        // Check for POST method
        if (c == 'P' && index == 0) {
          isPost = true;
        }

        if (isPost) {
          if (c != '\n' && c != '\r') {
            clientline[index] = c;
            index++;
            if (index >= BUFSIZE)
              index = BUFSIZE - 1;
            continue;
          }
        }
        else {
          if (c != '\n' && c != '\r') {
            clientline[index] = c;
            index++;
            if (index >= BUFSIZE)
              index = BUFSIZE - 1;
            continue;
          }
        }

        clientline[index] = 0;
        Serial.println(clientline);

        if (isPost) {
          // Handle POST request here
          // Parse the request body to extract data
          // Example: Extracting data from a simple form
          if (strstr(clientline, " ")) {
            int contentLength = atoi(strstr(clientline, " ") + 16);
            char postData[contentLength + 1];
            client.readBytes(postData, contentLength);
            postData[contentLength] = '\0';
            Serial.println(postData);

            // Convert postData to float
            float floatValue = 1.75;
            Serial.print("Float Value: ");
            Serial.println(floatValue);

            // Convert float to two 16-bit integers
            // Divida o valor float em duas partes (bytes)
            uint8_t intValue1 = (uint8_t)(floatValue);
            uint8_t intValue2 = (uint8_t)((floatValue - intValue1) * 100); // Multiplicado por 1000 para manter os três primeiros dígitos decimais

            Serial.println(intValue1);
            Serial.println(intValue1);
            // Write the two registers to Modbus address 1024
            node.writeSingleRegister(1024, intValue1);
            node.writeSingleRegister(1025, intValue2);

          }
          // Respond to the POST request with an alert box
          client.println("HTTP/1.1 200 OK");
          client.println("Content-Type: text/html");
          client.println("Connection: close");
          client.println();
          client.println("<!DOCTYPE html>");
          client.println("<html>");
          client.println("<head>");
          client.println("<title>Response</title>");
          client.println("<script>");
          client.println("alert('Setpoint alterado com sucesso!');");
          client.println("window.location.href = '/';");  // Redirecionar para a página inicial após o alerta
          client.println("</script>");
          client.println("</head>");
          client.println("<body>");
          client.println("</body>");
          client.println("</html>");
          break; // Break out of the loop after handling POST request
        }
        else {
          // Handle GET request here
          if (strstr(clientline, "ajax_inputs")) {
            HtmlHeaderOK_XML(client);
            XML_response(client);
          }
          else {
            // Tudo o resto é um 404
            HtmlHeader404(client);
          }
          break; // Break out of the loop after handling GET request
        }
      }
    }
    // Tempo para o Web Browser receber os dados
    delay(1);
    client.stop();
  }
}

void Calibration() {
  Serial.println("*******Calibração*******");
  Serial.print("Valor na saida analógica 0: ");
  Serial.println(analogRead(0));
  Serial.print("Valor a ser apresentado: ");
  Serial.println(freeChlorine);
  Serial.println();
  Serial.print("Valor na saida analógica 1: ");
  Serial.println(analogRead(1));
  Serial.print("Valor a ser apresentado: ");
  Serial.println(pH);
  Serial.println();
  Serial.print("Valor na saida analógica 2: ");
  Serial.println(analogRead(2));
  Serial.print("Valor a ser apresentado: ");
  Serial.println(temperature);
  Serial.println("************************");
}

float modbusResponce(int addr) {

  char serial[20];
  float finalresult;
  float aux;
  finalresult = node.readInputRegisters(addr, 4); // register adress, quantity


  if (finalresult == node.ku8MBSuccess) {
    //Serial.println("\nSerial Number: ");
    int dat = node.getResponseBuffer(0);
    int dat1 = node.getResponseBuffer(1);


    Serial.println(dat);
    Serial.println(dat1);

    serial[0] = highByte(dat);
    serial[1] = lowByte(dat);
    serial[2] = highByte(dat1);
    serial[3] = lowByte(dat1);

    Serial.println("--------------");
    Serial.print (serial[0]);
    Serial.print (serial[1]);
    Serial.print (serial[2]);
    Serial.print (serial[3]);
    Serial.println("----------------");

    /* converter as leituras de registos para float */
    union modbus {
      byte t[4];
      float tval;
    } modbusData;

    modbusData.t[0] = lowByte(dat1);
    modbusData.t[1] = highByte(dat1);
    modbusData.t[2] = lowByte(dat);
    modbusData.t[3] = highByte(dat);

    aux = modbusData.tval;

    /******************************/
    delay(100);
    return aux;
  }
  else {
    Serial.print("\nError ");
    delay(100);
    return -1;
  }


}

void setup() {
  pinMode(MAX485_RE_NEG, OUTPUT);
  pinMode(MAX485_DE, OUTPUT);
  digitalWrite(MAX485_RE_NEG, 0);
  digitalWrite(MAX485_DE, 0);

  Serial.begin(38400, SERIAL_8O1);
  node.begin(1, Serial);

  node.preTransmission(preTransmission);
  node.postTransmission(postTransmission);

  pinMode(10, OUTPUT);
  digitalWrite(10, HIGH);

  //Inicialização dos pins de alarme
  pinMode(ALARMPIN, INPUT);
  digitalWrite(ALARMPIN, HIGH);

  // Inicialização de Ethernet
  Ethernet.begin(mac, ip);
  server.begin();
}

int defineAlarm() {
  int aux;
  if (digitalRead(ALARMPIN) == HIGH) {
    aux = 1;
  }
  else {
    aux = 0;
  }
  return aux;
}

void loop() {

  if ((millis() % lastREAD) >= READ) {
    delay(100);
    freeChlorine = modbusResponce(146);
    pH = modbusResponce(154);
    temperature = modbusResponce(138);
    C1Value = modbusResponce(4098) * 100;
    C1Process = modbusResponce(4100);
    C1Disturbance = modbusResponce(4102);
    C1SetPoint = modbusResponce(4104);
    C2Value = modbusResponce(4354) * 100;
    C2Process = modbusResponce(4356);
    C2Disturbance = modbusResponce(4358);
    C2SetPoint = modbusResponce(4360);
    alarm = defineAlarm();
    lastREAD = millis();
  }

  analog_val4 = map(analogRead(3), 180, 945, 0, 180);
  analog_val5 = map(analogRead(4), 180, 950, 0, 180);
  analog_val6 = map(analogRead(5), 180, 890, 0, 180);
  analog_val7 = map(analogRead(6), 180, 927, 0, 180);

  /*
    Serial.println("");
    Serial.print("Cloro: ");
    Serial.println (freeChlorine);
    Serial.print("pH: ");
    Serial.println (pH);
    Serial.print("Temperatura: ");
    Serial.println (temperature);
    Serial.println("");
    Serial.print("C1Value: ");
    Serial.println (C1Value);
    Serial.print("C1Process: ");
    Serial.println (C1Process);
    Serial.print("C1Disturbance: ");
    Serial.println (C1Disturbance);
    Serial.print("C1SetPoint: ");
    Serial.println (C1SetPoint);
    Serial.println("");
    Serial.print("C2Value: ");
    Serial.println (C2Value);
    Serial.print("C2Process: ");
    Serial.println (C2Process);
    Serial.print("C2Disturbance: ");
    Serial.println (C2Disturbance);
    Serial.print("C2SetPoint: ");
    Serial.println (C2SetPoint);
    Serial.println("");
  */
  Connection();
}
