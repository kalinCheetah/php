<?php
require_once 'Database.php';
require_once 'Order.php';

/*
 * Тут представлена логика работы программы по добавлению заказа в БД:
 * сначала проверяем, что удалось забронировать. Подтверждаем и тогда заносим в БД запись.
*/

echo '<pre>';

checkBookingAndAddToDatabase();

echo '</pre>';
function checkBookingAndAddToDatabase() {
    // создаем объекты, с которыми будем работать: БД и сам заказ. 
    // заказ конструируется из тестовых рандомизированных данных
    $db = new Database;
    $order = new Order(...testGerenerationFields()); 
    // флаги по дефолту == false
    $bookingIsOk = false;
    $approveBookingIsOk = false;
    
    // проверяем бронирование
    $message = $order->checkBooking();
    
    // пока не будет уникальный barcode продолжаем генерировать заново
    // шанс неуникальности предельно мал (13 знаков), но действую в соответствие с ТЗ
    while (isset($message['error']) && $message['error'] == 'barcode already exists') {
        $error = true;
        $message = $order->checkBooking($error);
    }
    if ($message['message'] == 'order successfully booked') {
        echo 'Book is OK<br>';
        $bookingIsOk = true;
    }
    
    // подтверждаем бронирование
    $message = $order->approveBooking();
    
    if ($message['message'] == 'order successfully approved') {
        echo 'Approve is OK<br>';
        $approveBookingIsOk = true;
    }
    // если из API пришла ошибка, выводим ее текст
    if (isset($message['error'])) {
        print_r($message['error']);
    }
    
    // если бронирование && подтверждение бронирования прошли успешно, то добавляет заказ в БД
    if ($bookingIsOk && $approveBookingIsOk) {
        $db->addOrder(...$order->getAllFields());
    }
}

/* функция генерирует случайные значения для конструирования заказа */
function testGerenerationFields() {
    $event_ids = [1325, 5432, 6781, 8909, 1002];  // захардкоденные типы событий в виде id
    $event_id = $event_ids[mt_rand(0, count($event_ids) - 1)];
    $event_date = date('Y-m-d', strtotime('+' . mt_rand(0, 30) . ' days'));  // дата в пределах 30 дней от текущей
    $ticket_adult_price = 1000;
    $ticket_adult_quantity = mt_rand(1, 5);
    $ticket_kid_price = 700;
    $ticket_kid_quantity = mt_rand(1, 5);

    return [$event_id, $event_date, $ticket_adult_price, $ticket_adult_quantity, $ticket_kid_price, $ticket_kid_quantity];
}
