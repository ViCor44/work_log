#include <Ethernet.h>
#include <SPI.h>
#include <avr/pgmspace.h>

#define ALARMPIN 22

unsigned long lastCalibrationInterval = 0;
#define CALIBRATION_INTERVAL 10000

/***** Configurações Ethernet *****/
byte mac[] = { 0xDE, 0xAD, 0xBE, 0xEF, 0xFE, 0xE5 };
IPAddress ip(191,188,127, 15);
EthernetServer server(80);

/***** Variaveis a obter *****/
float freeChlorine;
float pH;
float temperature;
int alarm;

void setup() {
    Serial.begin(9600);  
    pinMode(10, OUTPUT);          
    digitalWrite(10, HIGH);       

    //Inicialização dos pins de alarme
    pinMode(ALARMPIN, INPUT); 
    digitalWrite(ALARMPIN, HIGH);     
    
    // Inicialização de Ethernet
    Ethernet.begin(mac, ip);    
    server.begin();

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

// Função que devolve o conteudo XML SEM MODBUS
void XML_response(EthernetClient client)
{
  String xml_parameters = "<?xml version = \"1.0\" ?><inputs><poolName>Jacuzzi</poolName><freeChlorine>" + String(freeChlorine, 2) + "</freeChlorine><pH>" + String(pH, 2) + "</pH><temperature>" + String(temperature, 1) + "</temperature><alarme>" + String(alarm) + "</alarme></inputs>";
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
        while (client.connected()) {
            if (client.available()) {
                char c = client.read();               
                if (c != '\n' && c != '\r') {
                    clientline[index] = c;
                    index++;                    
                    if (index >= BUFSIZE)
                        index = BUFSIZE -1;             
                    continue;
                }               
                clientline[index] = 0;           
                
                Serial.println(clientline);
           
               if (strstr(clientline, "ajax_inputs")) {
                    HtmlHeaderOK_XML(client);                    
                    XML_response(client);                    
                }                
                else {
                    // Tudo o resto é um 404
                    HtmlHeader404(client);
                }
                break;
            }
        }
        // Tempo para o Web Browser receber os dados
        delay(1);
        client.stop();
    }
}

void Calibration(){
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

void loop() {
  freeChlorine = analogRead(0)/196.66;
  pH = analogRead(1)/70.0;
  temperature = analogRead(2)/18.7;
  alarm = digitalRead(ALARMPIN);  
  Connection();
  if ((millis() % lastCalibrationInterval) >= CALIBRATION_INTERVAL){
    Calibration();
    lastCalibrationInterval = millis();  
  }
}
