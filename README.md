Rest Api for Bolt
======================
#### Build awesome extensions and powerfull webapps.

 - ¡Working!
 - Use Rest with JWT (json web token) or the native Bolt cookie.
 - Create, update, index and retrieve content in json, xml, and more.
 - Extensible (comming soon!)
___

#### [Read about Rest](https://en.wikipedia.org/wiki/Representational_state_transfer) 
#### [Read about JWT](https://jwt.io/)
___

#### Login with JWT.
curl -X POST -H "Cache-Control: no-cache" -H "Postman-Token: 18916ec5-de28-1dfb-956a-b6267855e98e" "https://example.com/auth/login?username=myuser&password=mypass"

#### Get the TOKEN.
the token is returned in the login response, in the X-Access-Token Header
___

X-Access-Token →Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw


#### Get list a contents : USE GET REQUEST
curl -X GET -H "Accept: application/json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages"

#### Retrieve one content: USE GET REQUEST
curl -X GET -H "Accept: application/json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages/1"

#### Create content: USE POST REQUEST and send the data in the body
curl -X POST -H "Accept: application/json" -H "Content-Type: application/json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages/1"

#### Update content:  USE PATCH REQUEST and send the data in the body
curl -X PATCH -H "Accept: application/json" -H "Content-Type: application/merge-patch+json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages/1"

