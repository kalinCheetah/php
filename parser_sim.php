<?php
date_default_timezone_set('Asia/Baku');

echo '<pre>';


$message = $_POST['message'];
routeMessage($message);
writeLog();

echo '</pre>';

/*
    записываем в одну из таблиц данные
*/
function appendToDb($row, $tableName)
{
    $con = connectToDb();
    switch ($tableName) {
        case 'Deposit':
            $row[5] *= 100;
            $row[4] *= 100;
            $query = "INSERT INTO Auto_MostAz_deposit (payment_date, card, merchant, payment_time, amount, balance, uploaded) VALUES ('" .
                                                $row[0] . "', '" . $row[1] . "', '" . $row[2] . "', '" . $row[3] . "', " . $row[4] . ", " . $row[5] . ", FALSE);";
            break;
        case 'Payout':
            $row[5] *= 100;
            $row[4] *= 100;
            $query = "INSERT INTO Auto_MostAz_payout (payout_date, card, merchant, payout_time, amount, balance, uploaded) VALUES ('" .
                                                $row[0] . "', '" . $row[1] . "', '" . $row[2] . "', '" . $row[3] . "', " . $row[4] . ", " . $row[5] . ", FALSE);";
            break;
        case 'Other':
            $query = "INSERT INTO Auto_MostAz_other (cur_date, cur_time, message, uploaded) VALUES ('" . 
                                                $row[0] . "', '" . $row[1] . "', '" . $row[2] . "', FALSE);";
            break;
    }
    $result = mysqli_query($con, $query);
    if (!$result) {
        print_r("Insert error!");
        mysqli_error($con);
    }
}

/*
    Создаем подключение к БД
*/
function connectToDb()
{
    $con = mysqli_connect('89.108.88.43', 'kalin', '***', 'payment_system');
    if (!$con) {
        print_r("Connetction error!");
        mysqli_connect_error();
    } else {
        print_r("Connect is OK");
    }
    mysqli_set_charset($con, 'utf-8');

    return $con;
}

/*
    логируем поступающие запросы
*/
function writeLog()
{
    file_put_contents('logs/sim_input_data.log', json_encode($_POST) . PHP_EOL, FILE_APPEND);
}

/*
    первым делом СМС от приложения веб-хуком бросается в скрипт и попадает в эту функцию. 
    по его первичным признакам мы определяем что это за шаблон соощения
    и запускам нужный обработчик.
    В системе существует 4 типа обрабатываемых шаблона. Если сообщение не подходит ни под один, 
    то его обрабатывает otherHandler();
    В зависимости от типа шаблона есть различия в его обработке. По коду эти отличия будут ясны.
*/
function routeMessage($message)
{
    $message = preg_replace('/\p{Cc}+/u', '', $message); // удаляем все управляющие символы

    if (substr($message, 0, 7) == 'Mebleg:') {
        if (strpos($message, 'akartapp')) {        // отдельный шаблон
            if (strpos($message, 'Kart:')) {       // наличие карты говорит, что это списание
                akartappPayoutHandler($message);
            } elseif (preg_match('/Qebul eden: [0-9]{4}/', $message)) {
                qebulEdenPayoutHandler($message);
            } else {
                akartappDepositHandler($message);  // иначе пополнение
            }
        } elseif (strpos($message, 'Unvan')) {
            meblegUnvanHandler($message);
        } else {
            meblegHandler($message);
        }

    } elseif (substr($message, 0, 7) == 'Amount:') {  // substr вместо pos из-за первого символа
        amountPaymentHandler($message);

    } elseif (strpos($message, 'terminal payment')) {
        terminalPaymentHandler($message);

    } elseif (strpos($message, 'поступило') || strpos($message, 'ACCOUNT TO ACCOUNT TRANSFER')) {
        m10PaymentHandler($message);

    } elseif (strpos($message, 'ard-to-Card') || strpos($message, 'ucc.auth')) {  // как обычно кроме первого символа строки
        cardToCardOrSuccAuthHandler($message);

    } else {
        // если СМС содержит Legv Edilme, то не должно попадать в other
        if (empty($message) || strpos($message, 'Edilme')) {
            return;
        }
        otherHandler($message);
    }
}

/*
Пример входных данных:
Mebleg: 331.00 AZN\nQebul eden: 4652\nBalans: 0.11 AZN \nYeni \"akart\" tetbiqi ile rahat istifadeye qapilarini ach. Ele indi yukle: https:\/\/bit.ly\/akartapp
*/
function qebulEdenPayoutHandler($message)
{
    // получаем сумму
    preg_match('/Mebleg(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/Balans(.*?)AZN/', $message, $match);
    $balance = getMoney($match[1]);
    // получаем мерчанта
    preg_match('/Qebul eden: [0-9]{4}/', $message, $match);
    $merchant = getDigits($match[0]);

    $date = date('Y-m-d');
    $card = '-';
    
    $time = date('H:i:s');

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];

    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);

    appendToDb($columns, 'Payout');
    sendCopyMessageToTg($message);
}

/*
Функция обрабатывает шаблон, который делится на снятие и пополнение и эти процессы обрабатываются отдельными функциями.
В данном случае это снятие. Снятие сопровождается ОПТ-кодом. 
Пример входных данных:
Mebleg: 510.00 AZN\nTarix: 09.03.2024 14:35:31\nKart: *5849\nMerchant: M10 TOP UP\nBalans: 83.00 AZN \n\"akart\"in butun ustunlukleri artiq yeni tetbiqde - ele indi yukle: https:\/\/bit.ly\/akartapp
*/
function akartappPayoutHandler($message)
{
    // получаем сумму
    preg_match('/Mebleg(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    // получаем мерчанта
    preg_match('/Merchant:(.*?)(Balance|Balans)/', $message, $match);
    $merchant = str_replace('\n', '', trim($match[1]));
    // получаем дату
    preg_match('/[0-9]{2}\.[0-9]{2}\.[0-9]{4}/', $message, $match);
    $date = str_replace('.', '-', $match[0]);
    // меняет местами день и год
    $date = makeDate($date);
    // получаем время
    $time = parseTime($message);
    preg_match('/Kart(.*?)Merchant/', $message, $match);
    $card = getDigits($match[1]);

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);

    appendToDb($columns, 'Payout');
    $tgMessage = 'Amount: -' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                 '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;

    sendCopyMessageToTg($tgMessage);
}

/*
Функция обрабатывает шаблон, который делится на снятие и пополнение и эти процессы обрабатываются отдельными функциями.
В данном случае это пополнение.
Пример входных данных:
Mebleg: 1.00 AZN Merchant: M10 ACCOUNT TO CARD Balans: 1.00 AZN "akart"in butun ustunlukleri artiq yeni tetbiqde - ele indi yukle: https://bit.ly/akartapp
*/
function akartappDepositHandler($message)
{
    // получаем сумму
    preg_match('/Mebleg(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    // дата и время - текущие
    $time = date('H:i:s');
    $date = date('Y-m-d');
    $card = '-';
    // получаем мерчанта
    preg_match('/Merchant:(.*?)(Balance|Balans)/', $message, $match);
    $merchant = trim($match[1]);

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];

    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);

    appendToDb($columns, 'Deposit');
    $tgMessage = 'Amount: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                 '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;

    sendCopyMessageToTg($tgMessage);
}

/*
Пример входных данных:
На пополнение:
"Card-to-Card: 18.01.24 16:42 www.birbank.az, AZ Card: ****6312 amount: 0.01 AZN Fee: 0.00 Balance: 6.02 AZN. Thank you. BankofBaku"
На снятие:
"Succ.auth.: 18.01.24 16:42 M10 TOP UP, AZ Card: ****6312 amount: 5.00 AZN Fee: 0.00 Balance: 6.01 AZN.Thank you.Bank of Baku.Info:145"
*/
function cardToCardOrSuccAuthHandler($message)
{
    // получаем сумму
    preg_match('/amount(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    // получаем дату
    preg_match('/[0-9]{2}\.[0-9]{2}\.[0-9]{2}/', $message, $match);
    $date = str_replace('.', '-', $match[0]);
    // меняет местами день и год
    $date = makeDate($date);
    // получаем время
    $time = parseTime($message);
    // получаем card
    preg_match('/ Card:(.*?)amount/', $message, $match);
    $card = trim($match[1]);
    preg_match('/[0-9]:[0-9]{2}(.*?)Card/', $message, $match);
    $merchant = trim($match[1]);
    // удаляем ", AZ"
    $merchant = str_replace(', AZ', '', $merchant);

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);

    if (strpos($message, 'ard-to-Card')) {
        appendToDb($columns, 'Deposit');
        $tgMessage = 'Amount: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    } else {
        appendToDb($columns, 'Payout');
        $tgMessage = 'Amount: -' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    }

    sendCopyMessageToTg($tgMessage);
}

function meblegHandler($message)
{
    // получаем сумму
    preg_match('/Mebleg(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    // получаем дату
    preg_match('/[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])/', $message, $match);
    $date = $match[0];
    // получаем время
    $time = parseTime($message);
    // получаем номер карты: вычленяем только цифры и форматируем под хххх*хххх,
    // где х - любая цифра.
    preg_match('/Kart:([0-9]{4})\**([0-9]{4})/', $message, $match);
    $card = $match[1] . '*' . $match[2];
    // получаем мерчанта
    preg_match('/Merchant:(.*?)Balans/', $message, $match);
    $merchant = trim($match[1]);
    $merchant = str_replace(', AZ', '', $merchant);

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем для отладки
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);
    // в зависимости от знака заносим в нужную таблицу БД
    if (strpos(substr($message, 0, 10), '+')) {
        appendToDb($columns, 'Deposit');
        $tgMessage = 'Mebleg: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    } elseif (strpos(substr($message, 0, 10), '-')) {
        appendToDb($columns, 'Payout');
        $tgMessage = 'Mebleg: -' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    }

    sendCopyMessageToTg($tgMessage);
}

function meblegUnvanHandler($message)
{
    // получаем сумму
    preg_match('/Mebleg(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    // получаем дату
    preg_match('/[0-9]{2}\.[0-9]{2}\.[0-9]{2}/', $message, $match);
    $date = str_replace('.', '-', $match[0]);
    // меняет местами день и год
    $date = makeDate($date);
    // получаем время
    $time = parseTime($message);
    // получаем merchant
    preg_match('/Unvan:(.*?)[0-9]{2}\.[0-9]{2}\.[0-9]{2}/', $message, $match);
    $merchant = trim($match[1]);
    $merchant = str_replace(', AZ', '', $merchant);

    if (preg_match('/edaxil(.*?)Unvan/', $message, $match) != 0) {
        $card = '***' . getDigits($match[0]);
    } elseif (preg_match('/ECOM(.*?)Unvan/', $message, $match) != 0) {
        $card = '***' . getDigits($match[0]);
    }

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);
    // в зависимости от полполнения/снятия продолжаем парсить и на запись в БД
    if (strpos(substr($message, 0, 10), '-')) {
        appendToDb($columns, 'Payout');
        $tgMessage = 'Mebleg: -' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    } else {
        appendToDb($columns, 'Deposit');
        $tgMessage = 'Mebleg: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    }


    sendCopyMessageToTg($tgMessage);
}

function terminalPaymentHandler($message)
{
    // получаем сумму
    preg_match('/medaxil(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    $merchant = 'mpay';
    $card = '-';
    // получаем дату
    preg_match('/[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])/', $message, $match);
    $date = $match[0];
    // получаем время
    $time = parseTime($message);

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);
    // заносим в БД
    appendToDb($columns, 'Deposit');

    $tgMessage = 'Amount: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant . 
                 '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    sendCopyMessageToTg($tgMessage);
}

function m10PaymentHandler($message)
{
    $message = preg_replace('/\p{Cc}+/u', '', $message); // удаляем все управляющие символы
    // получить сумму
    preg_match('/medaxil(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получить баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);

    $date = date('Y-m-d');
    $card = '-';
    $merchant = 'm10';
    $time = date('H:i:s');

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);
    // заносит в БД
    appendToDb($columns, 'Deposit');

    $tgMessage = 'Amount: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant . 
                 '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    sendCopyMessageToTg($tgMessage);
}

/*
    замечание: если операция снятия и в карте только 4 цифры, то в сообщении имеется 
    дополтельное поле Fee, но если в формате карты 8 цифр (допустим, 5439*5439), то
    поля Fee не будет
*/
function amountPaymentHandler($message)
{
    // получаем сумму
    preg_match('/Amount(.*?)AZN/', $message, $match);
    $amount = getMoney($match[1]);
    // получаем баланс
    preg_match('/(Balance|Balans)(.*?)AZN/', $message, $match);
    $balance = getMoney($match[2]);
    // получаем дату
    preg_match('/[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])/', $message, $match);
    $date = $match[0];
    $time = parseTime($message);
    // получаем номер карты. Он может быть в двух форматах: хххх*хххх или *хххх. 
    // В зависимости от ситуации форматируем подходящим образом
    preg_match('/Card:(.*?)Date/', $message, $match);
    $digitStr = getDigits($match[1]);
    if (iconv_strlen($digitStr) == 4) {
        $card = '*' . $digitStr;
    } elseif (iconv_strlen($digitStr) == 8) {
        $card = substr($digitStr, 4) . '*' . substr($digitStr, 4, 4);
    }
    // получаем мерчанта
    preg_match('/Merchant:(.*?)Balance/', $message, $match);
    $merchant = trim($match[1]); // удаляем пробелы
    $merchant = str_replace(', AZ', '', $merchant);

    $columns = [$date, $card, $merchant, $time, (float)$amount, (float)$balance];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);
    // в зависимости от знака заносим в нужную таблицу БД
    if (strpos(substr($message, 0, 10), '+')) {
        appendToDb($columns, 'Deposit');
        $tgMessage = 'Amount: ' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    } elseif (strpos(substr($message, 0, 10), '-')) {
        appendToDb($columns, 'Payout');
        $tgMessage = 'Amount: -' . $amount . '%0A' . 'Card: ' . $card . '%0A' . 'Merchant: ' . $merchant .
                     '%0A' . $date . ' ' . $time . '%0A' . 'Balance: ' . $balance;
    }

    sendCopyMessageToTg($tgMessage);
}

function otherHandler($message)
{
    $date = date('Y-m-d');
    $time = date('H:i:s');
    $columns = [$date, $time, $message];
    // логируем
    file_put_contents('logs/sim_output_data.log', json_encode($columns) . PHP_EOL, FILE_APPEND);
    // записываем в БД
    appendToDb($columns, 'Other');

    sendCopyMessageToTg($message);
}

/*
    Универсально обрабатываем время. 
    Учитываем разные форматы и приводим к одному:
    hh:mm:ss
*/
function parseTime($message)
{
    if (preg_match('/[0-9]{2}:[0-9]{2}:[0-9]{2}/', $message, $match)) {
        $time = $match[0];
    } elseif (preg_match('/[0-9]:[0-9]{2}:[0-9]{2}/', $message, $match)) {
        $time = date('H:i:s', strtotime($match[0]));
    } elseif (preg_match('/[0-9]{2}:[0-9]{2}/', $message, $match)) {
        $time = date('H:i:s', strtotime($match[0]));
    } elseif (preg_match('/[0-9]:[0-9]{2}/', $message, $match)) {
        $time = date('H:i:s', strtotime($match[0]));
    } else {
        $time = date('H:i:s');
    }

    if ($time == '00:00:00') {
        $time = date('H:i:s');
    }

    return $time;
}

/*
    получаем сырую строку с побочными символами, оставляем только цифры
    и форматируем под денежный формат
*/
function getMoney($str)
{
    $digitStr = getDigits($str);
    $decimalPart = substr($digitStr, -2);
    $floor = substr($digitStr, 0, -2);

    return $floor . '.' . $decimalPart;
}

/*
    вспомогательная функция для вычленения цифр, без дальнейшего форматирования
*/
function getDigits($str)
{
    $len = iconv_strlen($str);
    $digitStr = '';
    for ($i = 0; $i < $len; $i++) {
        // проверяем ASCII-символы
        if (ord($str[$i]) >= 48 && ord($str[$i]) <= 57) {
            $digitStr .= $str[$i];
        }
    }
    return $digitStr;
}

/*
Меняет местами год и день и тогда функция strtotime работает правильно
*/
function makeDate($dateStr)
{
    $dateComponents = explode('-', $dateStr);

    $rightOrderDateStr = $dateComponents[2] . '-' . $dateComponents[1] . '-' . $dateComponents[0];
    $rightDate = (date('Y-m-d', strtotime($rightOrderDateStr)));
    
    return $rightDate;
}

/*
    подготавливаем строку для отправки в чат от лица тг-бота.
    необходимо заменить переносы строк и символ '+' в формат URL
*/
function prepareBeforeSendToTg($message)
{
    $message = str_replace('+', '%2B', $message);
    $message = str_replace(chr(10), '%0A', $message);
    $message = str_replace(chr(13), '', $message);
    $message = str_replace('#', '', $message);

    return $message;
}

/*
    Отправляем копию СМС в тг-чат от лица бота
*/
function sendCopyMessageToTg($message)
{
    $message = prepareBeforeSendToTg($message);
    // логируем
    file_put_contents('logs/tg.log', $message . PHP_EOL, FILE_APPEND);
    $url = 'https://api.telegram.org/bot6359984844:AAEH-Aojx0fOQOpblrTJbdS7PaQh9wIPduc/sendMessage';
    $chat_id = 'chat_id=-1002136858441';
    $ch = curl_init();
    $options = [
        CURLOPT_URL => $url . '?' . $chat_id . '&' . 'text=' . $message,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false
    ];
    curl_setopt_array($ch, $options);
    $result = curl_exec($ch);
    // логирует ответ от бота
    $result = json_decode($result);
    print_r($result);
}

// ---------------------------------------------
// ниже хранятся вспомогательные функции, не участвующие в текущей бизнес-логике.

/*
    функция нужна была для преобразования ответа от API при записи в таблицы, чтобы
    определить последнюю строку, в которую происходила запись.
    Функция преобразует формат listName!A1:B3 в номер последней строки. В данном случае - 3.
*/

/*function getRowIdxByA1($rangeName)
{
    $notationA1 = explode(':', explode('!', $rangeName)[1])[0];
    $rowIdx = '';
    for ($i = 0; $i < strlen($notationA1); $i++) {
        if (ord($notationA1[$i]) >= 48 && ord($notationA1[$i]) <= 57) {
            $rowIdx .= $notationA1[$i];
        }
    }

    return $rowIdx;
}*/
