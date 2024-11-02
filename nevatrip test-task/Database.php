<?php
/*
 *  Данный класс позволяет выполнять необходимые операции с БД
*/
class Database {
    private $connection;

    /* инициализация объекта для работы с БД */
    public function __construct() {
        $this->connection = mysqli_connect('localhost', 'root', 'vfvfghbdtn74', 'nevatrip');
        if (!$this->connection) {
            print_r('Connection error!');
            mysqli_connect_error();
        } else {
            print_r("Connect is OK<br>");
        }
    }

    /* выполнить запрос */
    private function execQuery($query) {
        $result = mysqli_query($this->connection, $query);
        if (!$result) {
            mysqli_error($this->connection);
        }        
    }

    /* Добавить заказ в БД */
    public function addOrder($event_id, $event_date, $ticket_adult_price, $ticket_adult_quantity, 
                             $ticket_kid_price, $ticket_kid_quantity, $barcode, $equal_price, $created) {
        $query = "INSERT INTO aproved_orders (event_id, event_date, ticket_adult_price, ticket_adult_quantity, 
                                              ticket_kid_price, ticket_kid_quantity, barcode, equal_price, created)
                  VALUES (" . $event_id . ", '" . $event_date . "', " . $ticket_adult_price . ", " . $ticket_adult_quantity . 
                          ", " . $ticket_kid_price . ", " . $ticket_kid_quantity . 
                          ", '" . $barcode . "', " . $equal_price . ", '" . $created  . "');";
        $this->execQuery($query);
    }
}
