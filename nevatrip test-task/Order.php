<?php

class Order {
    public function __construct($event_id, $event_date, $ticket_adult_price, $ticket_adult_qunatity, $ticket_kid_price, $ticket_kid_quantity) {
        $this->event_id = $event_id;
        $this->event_date = $event_date;
        $this->ticket_adult_price = $ticket_adult_price;
        $this->ticket_adult_quantity = $ticket_adult_qunatity;
        $this->ticket_kid_price = $ticket_kid_price;
        $this->ticket_kid_quantity = $ticket_kid_quantity;
        $this->equal_price = $this->calculateOrderSum();   // считаем сумму заказа по имеющимся данным
        $this->barcode = $this->generationBarcode();
        $this->created = date('Y-m-d H:i:s');
    }

    private $event_id;
    private $event_date;
    private $ticket_adult_price;
    private $ticket_adult_quantity;
    private $ticket_kid_price;
    private $ticket_kid_quantity;
    private $barcode;
    private $equal_price;
    private $created;

    /* считаем сумму заказа */
    private function calculateOrderSum() {
        return $this->ticket_adult_price * $this->ticket_adult_quantity + 
               $this->ticket_kid_price   * $this->ticket_kid_quantity;
    }

    /* генерация Barcode из 13 случайных цифр от 0 до 9. Шанс неуникальности стремится к нулю */
    private function generationBarcode() {
        $numberDigitsInBarcode = 13;  // сделать это значение константой
        $barcode = '';
        for ($i = $numberDigitsInBarcode; $i > 0; --$i) {
            $barcode .= mt_rand(0, 9);
        }
        
        return $barcode;
    }

    /* геттер полей, предназначенных только для бронирования */
    private function getFieldsForBooking() {
        return [$this->event_id, $this->event_date, $this->ticket_adult_price, $this->ticket_adult_quantity, 
                $this->ticket_kid_price, $this->ticket_kid_quantity, $this->barcode];
    }

    /* public - потому что надо будет передать в объект БД вне класса */
    public function getAllFields() {
        return [...$this->getFieldsForBooking(), $this->equal_price, $this->created];
    }

    /* Часть с "как бы" API-запросом закомментированна */
    public function checkBooking($error = false) {
        if ($error) {
            $this->barcode = $this->generationBarcode();
        }
        /*$url = 'https://api.site.com/book';
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($this->getFieldsForBooking()),
        ];
        curl_setopt_array($ch, $options);
        $message = json_decode(curl_exec($ch));

        if (curl_error($ch)) {
            print_r(curl_error($ch));
        }
        curl_close($ch);*/
        $message['message'] = 'order successfully booked';
        //$message['error'] = 'barcode already exists';
        
        return $message;
    }

    /* Часть с "как бы" API-запросом закомментированна */
    public function approveBooking() {
        /*$url = 'https://api.site.com/approve';
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([$this->barcode]),
        ];
        curl_setopt_array($ch, $options);
        $message = json_decode(curl_exec($ch));

        if (curl_error($ch)) {
            print_r(curl_error($ch));
        }
        curl_close($ch);*/
        $message['message'] = 'order successfully approved';
        /*$message['error'] = 'event cancelled';
        $message['error'] = 'no tickets';
        $message['error'] = 'no seats';
        $message['error'] = 'fan removed';*/

        return $message;
    }
}