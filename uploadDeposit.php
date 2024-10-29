<?php
date_default_timezone_set('Asia/Baku');
putenv('GOOGLE_APPLICATION_CREDENTIALS=/var/www/html/AUTO_MostAz/azn-creds.json');

require_once __DIR__ . '/vendor/autoload.php';

echo '<pre>';

$client = getClient(); // для работы с API таблиц
$lastTime = getLastRow($client, $rowToWrite)[0];
$balances = getLast10Balances($client, $rowToWrite);
$unfilteredRows = getNewTransactions($lastTime);
if (!$unfilteredRows) {
    exit;
}
$filteredRows = filterRepeatsInBuffer($balances, $unfilteredRows);
appendBufferToGooglesheets($client, $rowToWrite, $filteredRows);
// ставим галочки столько раз, сколько новых строк записали в лист
for ($i = 0; $i < count($filteredRows); $i++) {
    copyCheckBox($client, $rowToWrite++);
}

echo '</pre>';

/*
    если при получении $lastTime у нас есть транзакции пришедшие в одну и ту же секунду,
    то при получении онных из БД возможны дубли. Для этого мы удаляем из пачки данных из БД
    все повторяемые, отталкиваясь от значения баланса
    Числа баланса и сумму храним в int, но при этом умножаем и делим на 100 при чтении/записи
*/
function filterRepeatsInBuffer($balances, $unfilteredRows)
{
    foreach ($balances as &$item) {
        $item[0] = str_replace(',', '.', $item[0]);  // подготавливаем строку к приведению к числу
        $item = intval(floatval($item[0]) * 100);  // строку -> float -> int. 
    }
    foreach ($unfilteredRows as &$row) {
        $row['balance'] = intval($row['balance']);  
    }
    // фильтруем
    foreach ($balances as $balance) {
        foreach ($unfilteredRows as $rowKey => $rowVal) {
            if ($unfilteredRows[$rowKey]['balance'] == $balance) {
                unset($unfilteredRows[$rowKey]);
                break;
            }
        }
    }

    return $unfilteredRows;
}

/*
    записываем данные в гугл-таблицы
*/
function appendBufferToGooglesheets($client, $rowToWrite, $data)
{
    foreach ($data as $row) {
        $values[] = [$row['payment_date'], $row['card'], $row['merchant'], $row['payment_time'], $row['amount'] / 100, $row['balance'] / 100];
    }
    $service = new Google\Service\Sheets($client);
    $body = new Google\Service\Sheets\ValueRange(['values' => $values]);
    $spreadsheetId = '1ulKLK3TfiKTMN0BXUV-kEhOEFtzzCm9dy8IEL_RbJlo';
    $range = 'Deposit!A' . $rowToWrite . ':F';
    $params = ['valueInputOption' => 'USER_ENTERED'];
    $response = $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
}

/*
    получаем все транзакции, записанные в БД после $lastTime в текущий день
    Отдельно приходится обрабатывать ситуацию с переходом к новым суткам
*/
function getNewTransactions($lastTime)
{
    $con = connectToDb();
    if ($lastTime >= '23:50:00' && $lastTime <= '23:59:59' && date('H:i:s') >= '00:00:01') {
        $query = "SELECT * FROM Auto_MostAz_deposit 
                  WHERE (payment_time >= '" . $lastTime . "' AND payment_date = '" . date('Y-m-d', strtotime('-1 days')) . "') AND
                        (payment_time >= '00:00:01' AND payment_date = '" . date('Y-m-d') . "');";
    } else {
        $query = "SELECT * FROM Auto_MostAz_deposit 
                  WHERE payment_time >= '" . $lastTime . "' AND payment_date = '" . date('Y-m-d') . "';";
    }
    $result = mysqli_query($con, $query);
    if (!$result) {
        print_r("Error!");
    }
    $emptyFlag = 1;   
    while ($row = mysqli_fetch_array($result)) {
        $emptyFlag = 0; 
        $rows[] = $row;
    }
    if ($emptyFlag) {   // если из БД ничего не взяли, то флаг выхода остается true
        return false;
    }
    return $rows;
}

/*
    Создаем подключение к БД
*/
function connectToDb()
{
    $con = mysqli_connect('localhost', 'root', ',***', 'payment_system');
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
    копируем галочку для листа Deposit из скрытого листа с технической информацией
    В конце каждой строки в гугл-таблице нужно ставить такую. Только в листе Deposit
    При использовании API быть внимательным с начальными и конечными границами копируемого элемента
*/
function copyCheckbox($client, $lastRowIdx)
{
    $copyRange = new Google\Service\Sheets\GridRange();
    $copyRange->setStartRowIndex(1);
    $copyRange->setEndRowIndex(1 + 1);
    $copyRange->setStartColumnIndex(0);
    $copyRange->setEndColumnIndex(0 + 1);
    $copyRange->setSheetId(673978277);

    $pasteRange = new Google\Service\Sheets\GridRange();
    $pasteRange->setStartRowIndex($lastRowIdx - 1);
    $pasteRange->setEndRowIndex($lastRowIdx);
    $pasteRange->setStartColumnIndex(6);
    $pasteRange->setEndColumnIndex(6 + 1);
    $pasteRange->setSheetId(0);

    $copyPasteReq = new Google\Service\Sheets\CopyPasteRequest();
    $copyPasteReq->setSource($copyRange);
    $copyPasteReq->setDestination($pasteRange);
    $copyPasteReq->setPasteOrientation('NORMAL');
    $copyPasteReq->setPasteType('PASTE_NORMAL');

    $service = new Google\Service\Sheets($client);
    $spreadsheetId = '1ulKLK3TfiKTMN0BXUV-kEhOEFtzzCm9dy8IEL_RbJlo';
    $batchReq = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest();
    $batchReq->setRequests(['copyPaste' => $copyPasteReq]);

    $response = $service->spreadsheets->batchUpdate($spreadsheetId, $batchReq);
}

/*
    получить последнее значение времени в листе Deposit
*/
function getLastRow($client, &$rowToWrite)
{
    $service = new Google\Service\Sheets($client);
    $spreadsheetId = '1ulKLK3TfiKTMN0BXUV-kEhOEFtzzCm9dy8IEL_RbJlo';
    $range = 'Deposit!D2:D';
    $result = $service->spreadsheets_values->get($spreadsheetId, $range);
    $rowToWrite = count($result->values) + 2;

    return end($result->values);
}

/*
    получить последние 10 балансов в таблице, чтобы, используя их, как идентификатор платежа,
    не дать продублироваться вносимым данным. 
*/
function getLast10Balances($client, $lastRow)
{
    $service = new Google\Service\Sheets($client);
    $spreadsheetId = '1ulKLK3TfiKTMN0BXUV-kEhOEFtzzCm9dy8IEL_RbJlo';
    $range = 'Deposit!F' . ($lastRow - 11) . ':F' . ($lastRow - 1);
    $result = $service->spreadsheets_values->get($spreadsheetId, $range);

    return $result->values;
}

function getClient()
{
    $client = new Google\Client();
    $client->useApplicationDefaultCredentials();
    $client->setScopes('https://www.googleapis.com/auth/spreadsheets');
    $client->setAccessType('offline');

    return $client;
}
