Rest Api for Bolt
======================
#### The backend for your applications.

 - Use Rest with JWT (json web token) 
 - Create, update, index and retrieve content in json
 - Follow the "json api" specification
 - Extensible (soon documentation!)

___

Use
======================

#### Login with JWT.

	curl -X POST -H "https://example.com/auth/login?username=myuser&password=mypass"

#### Get the TOKEN.
the token is returned in the login response, in the X-Access-Token Header

	X-Access-Token →Bearer eyJ0eXAiOiJKV165QiLCJh6G75d7iJIUzI1NiJ9.eyJpYXQiOjE0N57jQ1NMDgsImV4cCI6MTQ2NDU1ODE0NCwiZGF0YSI6eyJpZCI6InhuZXQifX0.dm7XqR91-Wl6zC9jupVVcu4khQz_LOq0cYf56BXHTIw

___

#### Get list a contents : USE GET REQUEST
	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages"

###### "filter" param
refine your result, use "||" ">" or "<"

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages?&filter[brand]=foo&filter[model]=bar&filter[status]=draft"
	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages?&filter[brand]=car&filter[brand]=bmw || fiat"
	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages?&filter[brand]=car&filter[id]=>100"

###### "deep" filter
when deep is enabled, the relationships be treated as one more field of content, useful if for example I want to search for content by the username, working with "filter" param.

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages?filter[contain]=john&filter[deep]=true"

###### "related" filter
refine your result according the related content 

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages&related=clients:5,10"

###### "unrelated" filter
exclude from the results content that is related to certain content type

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/review?filter[unrelated]=report:1"

###### "fields" param
limit the format of the result to the fields in the parameter

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/review?fields=title,details"

###### "page" param
paginate the results according this param, 
or return specific page

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/review?page[size]=10&page[num]=2"

###### "order" param
order the result by field or metedata, use "-" prefix with invert the natural order

	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/review?order=status"
	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/review?order=title"
	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/review?order=-title"

###### Use the response headers as pagination helpers
	'X-Total-Count' // total 
	'X-Pagination-Page' // actual page
	'X-Pagination-Limit' // limit by page

___
#### Retrieve one content: USE GET REQUEST
	curl -X GET -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages/1"

___
#### Create content: USE POST REQUEST and send the data in the body
	curl -X POST -H "Accept: application/json" -H "Content-Type: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages/1"
___
#### Update content:  USE PATCH REQUEST and send the data in the body
	curl -X PATCH -H "Accept: application/json" -H "Content-Type: application/merge-patch+json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages/1"
___
#### Delete content:  USE DELETE REQUEST for delete a content
If all goes well, the response should be a "204, not content"
	curl -X DELETE -H "Accept: application/json" -H "Authorization: Bearer here.myauth.token" -H "https://example.com/api/pages/1"
___

#### About REST and JWT
#### [Read about Rest](https://en.wikipedia.org/wiki/Representational_state_transfer)
#### [Read about JWT](https://jwt.io/)
___
