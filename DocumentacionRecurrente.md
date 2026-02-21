Recurrente: Documentación API
La API de Recurrente te permite crear sesiones de compra, manejar tus productos, suscripciones, y clientes, hacer transferencias de dinero entre diferentes cuentas de Recurrente, y mucho más.

Cómo empezar
Crea una cuenta en Recurrente.

La API responde en formato JSON. Cuando retorna un error, el error es enviado en un error key en JSON.

Autenticación
Para encontrar tus Llaves de API, dentro de tu cuenta de Recurrente, ve a:

Configuración → Llaves API.

Para autenticarte, debes enviar los siguientes headers en cada request:

Header	Description
X-PUBLIC-KEY	tu_llave_publica
X-SECRET-KEY	tu_llave_privada
Error en la autenticación
Si tus llaves de API no se están enviando o son inválidas, recibirás un código de respuesta HTTP 401 Unauthorized.

Sandbox y pagos de prueba
Existen dos formas de realizar pruebas: usando el ambiente Sandbox o haciendo pruebas directamente en producción, dependiendo del tipo de validación que necesites.

✅ Ambiente Sandbox
El ambiente Sandbox permite hacer pagos de prueba sin generar actividad real. Para utilizarlo:

Usa tus llaves de ambiente TEST.

Simula un pago exitoso con la tarjeta 4242 4242 4242 4242.

Los checkouts creados con llaves TEST:

Muestran un aviso que dice "PRUEBA" en el link de pago.

Tienen el atributo live_mode = false.

No crean actividad en la cuenta ni afectan el balance.

No disparan webhooks.

Este ambiente es ideal para pruebas durante la integración inicial o desarrollo. 

⚠️ Pruebas en producción
También es posible realizar pruebas en ambiente LIVE con tus llaves de producción. En estos casos:

Se recomienda reembolsar los pagos de prueba el mismo día, ya sea desde el panel de Recurrente o mediante la API en /api/refunds.

Los pagos reembolsados el mismo día son reembolsados al 100% del monto.

Esta opción permite validar el flujo completo, incluyendo actividad en cuenta, webhooks y conciliación. 

¿Necesitas ayuda?
Si estás usando Wordpress, te recomendamos que utilices nuestro plugin.

Únete y pregunta en nuestro Discord.

O envíanos un correo a soporte@recurrente.com

Tokenized Payments
Si quieres hacer un cobro a un cliente existente, usa tokenized payments.

POST
Create a one time payment (amount and currency)
https://app.recurrente.com/api/one_time_payments/
HEADERS
X-PUBLIC-KEY
{{recurrente_public_key}}

X-SECRET-KEY
{{recurrente_private_key}}

Body
formdata
payment_method_id
pay_m_l1aqgfoq

Encuentra este valor en el payload de un webhook exitoso, o al hacer GET /checkout en un checkout exitoso

items[][currency]
GTQ

La moneda a cobrar.

Posibles valores son:

GTQ
USD
items[][amount_in_cents]
3000

Monto a cobrar en centavos.

items[][name]
One Ripe Banana

(Opcional) El nombre del producto.

items[][image_url]
https://source.unsplash.com/400x400/?banana

(Opcional) URL de la imagen del producto.

items[][quantity]
1

(Opcional) Cantidad.

Si no incluyes una, el default es 1.

El valor mínimo es 1 y el valor máximo es 9.

Example Request
Respuesta exitosa
View More
curl
curl --location 'https://app.recurrente.com/api/one_time_payments' \
--header 'X-PUBLIC-KEY: {{recurrente_public_key}}' \
--header 'X-SECRET-KEY: {{recurrente_private_key}}' \
--header 'Content-Type: application/json' \
--data '{
  "items": [
    {
      "name": "One Ripe Banana",
      "currency": "GTQ",
      "amount_in_cents": 3000,
      "image_url": "https://source.unsplash.com/400x400/?banana",
      "quantity": 1
    }
  ],
  "success_url": "https://www.google.com",
  "cancel_url": "https://www.amazon.com",
  "user_id": "us_123456",
  "metadata": {}
}'
201 Created
Example Response
Body
Headers (0)
json
{
  "id": "on_123456789",
  "status": "paid"
}
POST
Create a one time payment (Product ID)
https://app.recurrente.com/api/checkouts/
HEADERS
X-PUBLIC-KEY
{{recurrente_public_key}}

X-SECRET-KEY
{{recurrente_private_key}}

Body
formdata
payment_method_id
pay_m_l1aqgfoq

Encuentra este valor en el payload de un webhook exitoso, o al hacer GET /checkout en un checkout exitoso

items[][product_id]
prod_1234567

Crea un producto primero, y luego utiliza el ID del producto.

items[][quantity]
1

(Opcional) Cantidad. Si no incluyes una, el default es 1. El valor mínimo es 1

Example Request
Respuesta exitosa
View More
curl
curl --location 'https://app.recurrente.com/api/one_time_payments' \
--header 'X-PUBLIC-KEY: {{recurrente_public_key}}' \
--header 'X-SECRET-KEY: {{recurrente_private_key}}' \
--header 'Content-Type: application/json' \
--data '{
  "items": [
    {
       "product_id": "prod_123456"
    }
  ],
  "metadata": {}
}'
201 Created
Example Response
Body
Headers (0)
json
{
  "id": "on_123456789",
  "status": "paid"
}


POST
Create a one time payment (Product ID)
https://app.recurrente.com/api/checkouts/
HEADERS
X-PUBLIC-KEY
{{recurrente_public_key}}

X-SECRET-KEY
{{recurrente_private_key}}

Body
formdata
payment_method_id
pay_m_l1aqgfoq

Encuentra este valor en el payload de un webhook exitoso, o al hacer GET /checkout en un checkout exitoso

items[][product_id]
prod_1234567

Crea un producto primero, y luego utiliza el ID del producto.

items[][quantity]
1

(Opcional) Cantidad. Si no incluyes una, el default es 1. El valor mínimo es 1



Public
ENVIRONMENT
No Environment
LAYOUT
Double Column
LANGUAGE
cURL - cURL
Recurrente: Documentación API
Introduction
Prueba tu autenticación
Checkouts
Customers
Payment Intents
Products
Coupons
Subscriptions
Users
Transfers
Refunds
Webhooks
Cuentas Conectadas
Embedded Checkouts
Tokenized Payments
Recurrente: Documentación API
La API de Recurrente te permite crear sesiones de compra, manejar tus productos, suscripciones, y clientes, hacer transferencias de dinero entre diferentes cuentas de Recurrente, y mucho más.

Cómo empezar
Crea una cuenta en Recurrente.

La API responde en formato JSON. Cuando retorna un error, el error es enviado en un error key en JSON.

Autenticación
Para encontrar tus Llaves de API, dentro de tu cuenta de Recurrente, ve a:

Configuración → Llaves API.

Para autenticarte, debes enviar los siguientes headers en cada request:

Header	Description
X-PUBLIC-KEY	tu_llave_publica
X-SECRET-KEY	tu_llave_privada
Error en la autenticación
Si tus llaves de API no se están enviando o son inválidas, recibirás un código de respuesta HTTP 401 Unauthorized.

Sandbox y pagos de prueba
Existen dos formas de realizar pruebas: usando el ambiente Sandbox o haciendo pruebas directamente en producción, dependiendo del tipo de validación que necesites.

✅ Ambiente Sandbox
El ambiente Sandbox permite hacer pagos de prueba sin generar actividad real. Para utilizarlo:

Usa tus llaves de ambiente TEST.

Simula un pago exitoso con la tarjeta 4242 4242 4242 4242.

Los checkouts creados con llaves TEST:

Muestran un aviso que dice "PRUEBA" en el link de pago.

Tienen el atributo live_mode = false.

No crean actividad en la cuenta ni afectan el balance.

No disparan webhooks.

Este ambiente es ideal para pruebas durante la integración inicial o desarrollo. 

⚠️ Pruebas en producción
También es posible realizar pruebas en ambiente LIVE con tus llaves de producción. En estos casos:

Se recomienda reembolsar los pagos de prueba el mismo día, ya sea desde el panel de Recurrente o mediante la API en /api/refunds.

Los pagos reembolsados el mismo día son reembolsados al 100% del monto.

Esta opción permite validar el flujo completo, incluyendo actividad en cuenta, webhooks y conciliación. 

¿Necesitas ayuda?
Si estás usando Wordpress, te recomendamos que utilices nuestro plugin.

Únete y pregunta en nuestro Discord.

O envíanos un correo a soporte@recurrente.com

Tokenized Payments
Si quieres hacer un cobro a un cliente existente, usa tokenized payments.

POST
Create a one time payment (amount and currency)
https://app.recurrente.com/api/one_time_payments/
HEADERS
X-PUBLIC-KEY
{{recurrente_public_key}}

X-SECRET-KEY
{{recurrente_private_key}}

Body
formdata
payment_method_id
pay_m_l1aqgfoq

Encuentra este valor en el payload de un webhook exitoso, o al hacer GET /checkout en un checkout exitoso

items[][currency]
GTQ

La moneda a cobrar.

Posibles valores son:

GTQ
USD
items[][amount_in_cents]
3000

Monto a cobrar en centavos.

items[][name]
One Ripe Banana

(Opcional) El nombre del producto.

items[][image_url]
https://source.unsplash.com/400x400/?banana

(Opcional) URL de la imagen del producto.

items[][quantity]
1

(Opcional) Cantidad.

Si no incluyes una, el default es 1.

El valor mínimo es 1 y el valor máximo es 9.

Example Request
Respuesta exitosa
View More
curl
curl --location 'https://app.recurrente.com/api/one_time_payments' \
--header 'X-PUBLIC-KEY: {{recurrente_public_key}}' \
--header 'X-SECRET-KEY: {{recurrente_private_key}}' \
--header 'Content-Type: application/json' \
--data '{
  "items": [
    {
      "name": "One Ripe Banana",
      "currency": "GTQ",
      "amount_in_cents": 3000,
      "image_url": "https://source.unsplash.com/400x400/?banana",
      "quantity": 1
    }
  ],
  "success_url": "https://www.google.com",
  "cancel_url": "https://www.amazon.com",
  "user_id": "us_123456",
  "metadata": {}
}'
201 Created
Example Response
Body
Headers (0)
json
{
  "id": "on_123456789",
  "status": "paid"
}
POST
Create a one time payment (Product ID)
https://app.recurrente.com/api/checkouts/
HEADERS
X-PUBLIC-KEY
{{recurrente_public_key}}

X-SECRET-KEY
{{recurrente_private_key}}

Body
formdata
payment_method_id
pay_m_l1aqgfoq

Encuentra este valor en el payload de un webhook exitoso, o al hacer GET /checkout en un checkout exitoso

items[][product_id]
prod_1234567

Crea un producto primero, y luego utiliza el ID del producto.

items[][quantity]
1

(Opcional) Cantidad. Si no incluyes una, el default es 1. El valor mínimo es 1

Example Request
Respuesta exitosa
View More
curl
curl --location 'https://app.recurrente.com/api/one_time_payments' \
--header 'X-PUBLIC-KEY: {{recurrente_public_key}}' \
--header 'X-SECRET-KEY: {{recurrente_private_key}}' \
--header 'Content-Type: application/json' \
--data '{
  "items": [
    {
       "product_id": "prod_123456"
    }
  ],
  "metadata": {}
}'
201 Created
Example Response
Body
Headers (0)
json
{
  "id": "on_123456789",
  "status": "paid"
}

aqui esta toda nla dcoemyetaciond er receurreta aydua a inetarr esto para lso aprgo contarjkeda de fornmet y backeden de la aplciacion pedue 