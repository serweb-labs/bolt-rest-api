Rest Api for Bolt
======================
#### Build awesome extensions and powerfull webapps.

 - ¡Working!
 - Use Rest with JWT (json web token) or the native Bolt cookie.
 - Create, update, index and retrieve content in json, xml, and more.
 - Extensible (comming soon!)
_____________

#### [Read about Rest](https://en.wikipedia.org/wiki/Representational_state_transfer) 
#### [Read about JWT](https://jwt.io/)
_________

#### Get Token.
curl -X POST -H "Cache-Control: no-cache" -H "Postman-Token: 18916ec5-de28-1dfb-956a-b6267855e98e" "https://example.com/auth/login?username=myuser&password=mypass"

#### Response.
X-Access-Token →Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw


####  Index content
curl -X GET -H "Accept: application/json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages"

#### Retrieve content
curl -X GET -H "Accept: application/json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages/1"

#### Create content
curl -X POST -H "Accept: application/json" -H "Content-Type: application/json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages/1"

#### Create content
curl -X PATCH -H "Accept: application/json" -H "Content-Type: application/merge-patch+json" -H "Authorization: Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw" -H "https://example.com/api/pages/1"

