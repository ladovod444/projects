<?php
/**
 * @package Joomla.JoomShopping.Products
 * @version 1.0.0
 * @author Dmitry (Dmitry Zadorozhny)
 * @website https://newwallet.ru
 */
defined('_JEXEC') or die('Restricted access');
jimport('joomla.application.component.controller');

class plgJshoppingCheckoutNewwallet_Cdek extends JPlugin {

    const RUSSIAN_POST = 2;
    const CDEK_COURIER = 1;
    const CDEK_ISSUE_POINT = 4;
    const INTERNATIONAL_RUSSIAN_POST = 5;
    const PAYMENT_CASH = 1;
    const DADATA_TOKEN = '234f9ccf79f0a22029c7265faa499a5350480f7e';
    const TARIFF_EXPRESS_LIGHT = 11;
    const TARIFF_EXPRESS = 137;
    const TARIFF_ISSUE_POINT = 136;
    const CDEK_SECURITY_CODE = '25bf1aa515f10a8f0fb3f07e69581d3c';
    const CDEK_ACCOUNT = '487c79edbc154d66d20e5ba88ce44c6e';
    const SELLER_ADDRESS = 'Аптекарский пр., д.4, оф.5';
    const SELLER_PHONE = '(812)9580276';
    const SEND_CITY_CODE = '137';

    /*
     * After order complete full event callback
     */
    function onAfterCreateOrderFull($order, $cart) {
        $this->createCdekOrder($order->order_id, $cart);
    }

    public function createCdekOrder($order_id, $cart) {
        // получить заказ
        $db = JFactory::getDbo();
        $query = $db->getQuery(TRUE);
        $query->select('*');
        $query->from('#__jshopping_orders');
        $query->where($db->quoteName('order_id') . ' = ' . $order_id);
        $db->setQuery($query);

        $result = $db->loadAssoc();

        if (empty($result['ext_field_3'])) { // если заказ ранее не отправлялся в сдек

            // Выполняется для типов доставки Сдек
            if ($result['shipping_method_id'] == self::RUSSIAN_POST
                || $result['shipping_method_id'] == self::INTERNATIONAL_RUSSIAN_POST) {
                return;
            }

            // получить товары заказа
            $db = JFactory::getDbo();
            $query = $db->getQuery(TRUE);
            $query->select('*');
            $query->from('#__jshopping_order_item');
            $query->where($db->quoteName('order_id') . ' = ' . $order_id);

            $db->setQuery($query);
            $result_goods = $db->loadAssocList();

            $items = '';
            $total_weight = 0;

            foreach ($result_goods as $good) {
                $amount = (int)$good['product_quantity'];
                $price = $good['product_item_price'];

                if ($result['order_discount']) {
                    $discount = $result['order_discount'] / $result['order_subtotal'];
                    $price = $price - $price * $discount;
                }

                $product_name = $good['product_item_price'];

                $payment = $result['payment_method_id'] == self::PAYMENT_CASH ? $price : 0;
                $item_weight = $good['weight'] * 1000;
                $items .= '<Item WareKey="' . $good['product_id'] . '" Cost="' . $price . '" Payment="' . $payment . '" Weight="' . $item_weight . '" Amount="' . $amount . '" Comment="' . $product_name . '"/>';

                $total_weight += $good['weight'];
            }

            $total_weight = $total_weight * 1000;

            if ($result['payment_method_id'] == self::PAYMENT_CASH) {
                $DeliveryRecipientCost = !empty($result['order_shipping']) ? $result['order_shipping'] : 0;
            } else {
                $DeliveryRecipientCost = 0;
            }

            // получение id города из таблицы cdek_cities
            $receiverCity = $result['city'];
            $query = $db->getQuery(TRUE)
                ->select($db->quoteName('ID', 'id'))
                ->select($db->quoteName('FullName', 'city'))
                ->from($db->quoteName('#__cdek_cities'))
                ->where('FullName' . ' LIKE "' . $receiverCity . '%"')
                ->order('CityName ASC');
            $receiverCityId = $db->setQuery($query)->loadObject();

            $address = '';

            // Доставка курьером Сдек
            if ($result['shipping_method_id'] == self::CDEK_COURIER) {
                $user_address = $result['street'];

                // разбить данные из строки поля адреса
                $check_user_address = explode(',', $user_address);
                if (count($check_user_address) && count($check_user_address) >= 5) {
                    $check_user_address = array_slice($check_user_address, 0, 4);
                    $user_address = implode(', ', $check_user_address);
                }

                // Запрос к сервису dadata для "нормализации" данных по адресу
                $url = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';

                $address_data = array(
                    "query" => $receiverCity . ', ' . $user_address,
                    "count" => 1
                );

                if ($ch_dadata = curl_init($url)) {
                    curl_setopt($ch_dadata, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch_dadata, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Token ' . self::DADATA_TOKEN,
                    ));
                    curl_setopt($ch_dadata, CURLOPT_POST, 1);
                    curl_setopt($ch_dadata, CURLOPT_POSTFIELDS, json_encode($address_data));
                    $dadata_result = curl_exec($ch_dadata);
                    $dadata_result = json_decode($dadata_result, TRUE);
                    curl_close($ch_dadata);

                    if (!empty($dadata_result['suggestions'])) {
                        $cdek_address_arr = array();
                        $cdek_address_arr['street'] = !empty($dadata_result['suggestions'][0]['data']['street']) ?
                            $dadata_result['suggestions'][0]['data']['street'] : '';
                        $cdek_address_arr['house'] = !empty($dadata_result['suggestions'][0]['data']['house']) ?
                            $dadata_result['suggestions'][0]['data']['house'] : '';
                        $cdek_address_arr['flat'] = !empty($dadata_result['suggestions'][0]['data']['flat']) ?
                            $dadata_result['suggestions'][0]['data']['flat'] : '';
                    }

                }

                if ($cdek_address_arr['street'] && $cdek_address_arr['house']) {
                    $address = '<Address Street="' . $cdek_address_arr['street'] . '" House="' . $cdek_address_arr['house'] . '"  />';
                }

                if ($cdek_address_arr['street'] && $cdek_address_arr['house'] && $cdek_address_arr['flat']) {
                    $address = '<Address Street="' . $cdek_address_arr['street'] . '" House="' . $cdek_address_arr['house'] . '" Flat="' . $cdek_address_arr['flat'] . '"  />';
                }

                $tariffTypeCode = self::TARIFF_EXPRESS;
                $session = JFactory::getSession();
                $is_expressLight = $session->get("expressLight");
                if ($is_expressLight) {
                    $tariffTypeCode = self::TARIFF_EXPRESS_LIGHT;
                }
            }

            // Доставка Сдек ПВЗ
            if ($result['shipping_method_id'] == self::CDEK_ISSUE_POINT) {
                $deliveCode = $result['ext_field_2'];

                $address = '<Address PvzCode="' . $deliveCode . '" />';
                $tariffTypeCode = self::TARIFF_ISSUE_POINT;
            }

            // Сделать запрос по отправке заявке в аккаугт Сдек
            if (!empty($address)) {
                $date = date('c');
                $secure = md5($date . '&' . self::CDEK_SECURITY_CODE);
                $xml = '<?xml version="1.0" encoding="UTF-8"?>
<DeliveryRequest Number="' . $result['order_id'] . '" Date="' . $date . '" Account="' . self::CDEK_ACCOUNT . '" Secure="' . $secure . '" OrderCount="1">
    <Order Number="' . $result['order_id'] . '"
	DeliveryRecipientCost="' . $DeliveryRecipientCost . '"
	SendCityCode="' . self::SEND_CITY_CODE . '"
	RecCityCode="' . $receiverCityId->id . '"
	RecipientName="' . $result['f_name'] . ' ' . $result['l_name'] . '"
	RecipientEmail="' . $result['email'] . '"
	Phone="' . $result['phone'] . '"
	SellerAddress="' . self::SELLER_ADDRESS . '"
	SendPhone="' . self::SELLER_PHONE . '"
	Comment=""
	TariffTypeCode="' . $tariffTypeCode . '"
	RecientCurrency="RUB"
	ItemsCurrency="RUB">
	' . $address . '
	<Package Number="1" BarCode="' . $result['order_id'] . '" Weight="' . $total_weight . '">'
                    . $items .
                    '</Package>
    </Order>
</DeliveryRequest>';

                $data = array('xml_request' => $xml);
                $urls = "https://integration.cdek.ru/new_orders.php";
                $data_string = http_build_query($data);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $urls);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/x-www-form-urlencoded',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            }

            $results = curl_exec($ch);

            // в случае успешной отправки заказа в сдек заполнить поля заказа
            $results_from_xml = json_decode(json_encode((array)simplexml_load_string($results)), TRUE);
            if (!isset($results_from_xml['Order'][0]['@attributes']['ErrorCode'])) {
                $cdek_number = $results_from_xml['Order'][0]['@attributes']['DispatchNumber'];

                //задать значение поля и обновить заказ
                $order_to_update = new stdClass();
                $order_to_update->order_id = $result['order_id']; //
                $order_to_update->ext_field_3 = $cdek_number;
                JFactory::getDbo()
                    ->updateObject('#__jshopping_orders', $order_to_update, 'order_id');
            }

            $log = date('d.m.Y H:i:s') . ' Order_id: ' . $result['order_id'] . "\n" . print_r($results, TRUE);
            file_put_contents('nw_cdek.log', $log . "\n", FILE_APPEND | LOCK_EX);

            curl_close($ch);
        }
    }

}
