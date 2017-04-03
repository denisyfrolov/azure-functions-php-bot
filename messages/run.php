<?php
  
  //Получаем или устанавливаем ID и пароль приложения для аутентификации бота
  //В этом примере я получаю параметры из переменных окружения, но вы можете установить их напрямую в коде
  $client_id = getenv('MicrosoftAppId');
  $client_secret = getenv('MicrosoftAppPassword');

  //Устанавливаем URL, к которому будем обращаться за токеном авторизации
  $authRequestUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
  
  //Читаем тело POST-запроса с сообщением от пользователя и десериализуем JSON в массив $deserializedRequestActivity
  //В этом примере я получаю тело запроса из переменной окружения req, что преднастроено в биндингах Azure Functions
  //Вы можете получать тело запроса из потока php://input, если не используете Azure Functions
  $request = file_get_contents(getenv('req')); 
  //$request = file_get_contents('php://input'); //если не используете Azure Functions
  $deserializedRequestActivity = json_decode($request, true);

  //Если $deserializedRequestActivity содержит поле id, считаем входящий запрос корректным и начинаем обработку
  if(isset($deserializedRequestActivity['id']))
  {

    //Прежде всего, готовим запрос токена для авторизации ответа на сообщение. Токен можно получить через POST-запрос к oAuth сервису Microsoft.
    //Я использую stream context для запроса только потому, что его реализация выглядит нагляднее, вы можете использовать CURL.
    $authRequestOptions = array(
        'http' => array(
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query(
                array(
                    'grant_type' => 'client_credentials',
                    'client_id' => $client_id, //ID приложения
                    'client_secret' => $client_secret, //Пароль приложения
                    'scope' => 'https://graph.microsoft.com/.default'
                )
            )
        )
    );

    //Создаем сконфигурированный выше stream context и выполняем из него запрос к oAuth сервису
    $authRequestContext  = stream_context_create($authRequestOptions);
    
    //Читаем ответ на запрос и десериализуем его в массив $authData
    $authResult = file_get_contents($authRequestUrl, false, $authRequestContext);
    $authData = json_decode($authResult, true);

    //Если $authData содержит поле access_token, считаем аутентификацию успешной и продолжаем обработку
    if(isset($authData['access_token']))
    {

        //Определяем какой тип сообщения мы получили
        switch ((string)$deserializedRequestActivity['type']) {
            case 'message':
                //Готовим текст ответа на сообщение в случае, если тип входящего сообщения message
                $message = 'New message is received: ' . (string)$deserializedRequestActivity['text'];
                break;
            
            default:
                //В этом примере мы не будем обрабатывать все прочие типы сообщений, поэтому просто говорим, что мы не знакомы со всеми остальными типами
                $message = 'Unknown type';
                break;
        }

        //Формируем массив $deserializedResponseActivity с данными ответа, который позже передадим в Microsoft Bot Framework 
        $deserializedResponseActivity = array(

            //Мы отвечаем обычным сообщением
            'type' => 'message',

             //Текст ответа на сообщение
            'text' => $message,
            
            //Говорим, что ответ - это простой текст
            'textFormat' => 'plain', 

            //Устанавливаем локаль ответа
            'locale' => 'ru-RU', 

            //Устанавливаем внутренний ID активности, в контексте которого мы находимся (берем из поля id входящего POST-запроса с сообщением)
            'replyToId' => (string)$deserializedRequestActivity['id'],  

            //Сообщаем id и имя участника чата (берем из полей recipient->id и recipient->name входящего POST-запроса с сообщением, то есть id и name, которым было адресовано входящее сообщение)
            'from' => array(
                'id' => (string)$deserializedRequestActivity['recipient']['id'], 
                'name' => (string)$deserializedRequestActivity['recipient']['name']
            ),

            //Устанавливаем id и имя участника чата, к которому обращаемся, он отправил нам входящее сообщение (берем из полей from->id и from->name входящего POST-запроса с сообщением)
            'recipient' => array(
                'id' => (string)$deserializedRequestActivity['from']['id'],
                'name' => (string)$deserializedRequestActivity['from']['name']
            ),

            //Устанавливаем id беседы, в которую мы отвечаем (берем из поля conversation->id входящего POST-запроса с сообщением)
            'conversation' => array(
                'id' => (string)$deserializedRequestActivity['conversation']['id'] 
            )
        );

        //Формируем URL, куда передадим ответ на сообщение. По сути он собирается из параметров входящего POST-запроса и выглядит следующим образом:
        // https://{activity.serviceUrl}/v3/conversations/{activity.conversation.id}/activities/{activity.id}
        // где activity - это входящий POST-запроса с сообщением, десериализованный ранее в массив $deserializedRequestActivity
        // {activity.serviceUrl} пропускается через rtrim что бы исключить последний закрывающий слеш, потому что иногда он есть, а иногда его нет
        // {activity.id} необходимо пропустить через urlencode, потому что в нем встречаются специальные символы, которые ломают URL и мешают выполнить запрос
        $responseActivityRequestUrl = rtrim($deserializedRequestActivity['serviceUrl'], '/') . '/v3/conversations/' . $deserializedResponseActivity['conversation']['id'] . '/activities/' . urlencode($deserializedResponseActivity['replyToId']);

        //Готовим POST-запрос к Microsoft Bot Connector API, в котором передадим ответ на входящее сообщение
        //Я использую stream context для запроса только потому, что его реализация выглядит нагляднее, вы можете использовать CURL.
        $responseActivityRequestOptions = array(
            'http' => array(

                //Устанавливаем в заголовок POST-запроса данные для авторизации ответа, тип токена (token_type) и сам токен (access_token)
                'header'  => 'Authorization: ' . $authData['token_type'] . ' ' . $authData['access_token'] . "\r\nContent-type: application/json\r\n",
                'method'  => 'POST',

                //В тело запроса вставляем сериализованный в JSON-формат массив с данными ответа $deserializedResponseActivity
                'content' => json_encode($deserializedResponseActivity)
            )
        );

        //Создаем stream context и выполняем из него сконфигурированный выше запрос к Microsoft Bot Connector API
        $responseActivityRequestContext  = stream_context_create($responseActivityRequestOptions);
        $responseActivityResult = file_get_contents($responseActivityRequestUrl, false, $responseActivityRequestContext);

        //Пишим в поток STDOUT лог о получении и обработке очередного сообщения
        fwrite(STDOUT, 'New message is received: "' . (string)$deserializedRequestActivity['text'] . '"');

    }
    
  }
?>