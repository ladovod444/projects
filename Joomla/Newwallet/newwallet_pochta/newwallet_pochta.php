<?php
/**
 * @package Joomla.JoomShopping.Products
 * @version 1.0.0
 * @author Dmitry (Dmitry Zadorozhny)
 * @website https://newwallet.ru
 */
defined('_JEXEC') or die('Restricted access');
jimport('joomla.application.component.controller');

class plgJshoppingCheckoutNewwallet_Pochta extends JPlugin {

    const RUSSIAN_POST = 2;
    const PAYMENT_CASH = 1;
    const TRAVEL_KIT_ID = 10;
    const POCHTA_KEY = 'aW5mb0BuZXd3YWxsZXQucnU6ZHg2MGhKTjNrL2R4Xw==';
    const POCHTA_TOKEN = 'ArRltqR60XPnCJGYPLeS9r00Ay5lTp4_';

    const POCHTA_ENVELOPE = 0.022;
    const POCHTA_ENVELOPE_TRAVEL_KIT =  0.042;

    /*
     * After order complete full event callback
     */
	function onAfterCreateOrderFull($order, $cart) {
		$this->createPochtaOrder($order->order_id);
	}

	public function createPochtaOrder($order_id) {

		$db = JFactory::getDbo();
		$query = $db->getQuery(TRUE);
		$query->select('*');
		$query->from('#__jshopping_orders');
		$query->where($db->quoteName('order_id') . ' = ' . $order_id);

		$db->setQuery($query);

		$result = $db->loadAssoc();

        // проверка что доставка почтой и поле 'ext_field_3 (доп. инфо) пустое
		if ($result['shipping_method_id'] == self::RUSSIAN_POST && empty($result['ext_field_3'])) {

			// проверить существование заказа с таким номером
			$request_e = curl_init('https://otpravka-api.pochta.ru/1.0/backlog/search?query='. $order_id);
			$headers_e[] = 'Authorization: AccessToken ' . POCHTA_TOKEN;
			$headers_e[] = 'X-User-Authorization: Basic ' . POCHTA_KEY;
			$headers_e[] = 'Content-Type: application/json';
			$headers_e[] = 'Accept: application/json;charset=UTF-8';

			curl_setopt($request_e, CURLOPT_HTTPHEADER, $headers_e);
			curl_setopt($request_e, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($request_e, CURLOPT_CUSTOMREQUEST, 'GET');
			$data_by_index = curl_exec($request_e);
			curl_close($request_e);

			if ($data_by_index) {
				$data_by_index = json_decode($data_by_index);

				// если заказ существует в ЛК Почты то функция завершается
				if (count($data_by_index)) {
					return;
				}
			}

            // получить товары заказа
			$db = JFactory::getDbo();
			$query = $db->getQuery(TRUE);
			$query->select('*');
			$query->from('#__jshopping_order_item');
			$query->where($db->quoteName('order_id') . ' = ' . $order_id);

			$db->setQuery($query);
			$result_goods = $db->loadAssocList();

			$has_travel_kit = FALSE;
			$total_weight = 0;
			foreach ($result_goods as $good) {
				$amount = (int) $good['product_quantity'];
				$total_weight += $good['weight'] * $amount;

				if ($good['category_id'] == self::TRAVEL_KIT_ID) { // есть товар из категории TravelKit
					$has_travel_kit = TRUE;
				}

			}

			if ($has_travel_kit) {
				$total_weight += self::POCHTA_ENVELOPE_TRAVEL_KIT;
			}
			else {
				$total_weight += self::POCHTA_ENVELOPE;
			}

			$total_weight = $total_weight * 1000;

			// подготовить номер телефона
			$phone = str_replace(array(' ', '(', ')', '-'), '', $result['phone']);

			// Получить регион по индексу
			$request_r = curl_init('https://otpravka-api.pochta.ru/postoffice/1.0/'. $result['zip']);
			$headers_r[] = 'Authorization: AccessToken ' . POCHTA_TOKEN;
			$headers_r[] = 'X-User-Authorization: Basic ' . POCHTA_KEY;
			$headers_r[] = 'Content-Type: application/json';
			$headers_r[] = 'Accept: application/json;charset=UTF-8';

			curl_setopt($request_r, CURLOPT_HTTPHEADER, $headers_r);
			curl_setopt($request_r, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($request_r, CURLOPT_CUSTOMREQUEST, 'GET');
			$data_by_index = curl_exec($request_r);
			curl_close($request_r);

			if ($data_by_index) {
				$data_by_index = json_decode($data_by_index);
				$region = $data_by_index->region;
			}

			if ($region) { // проверить регион
				//Данные для отправки
				$data = array("address-type-to" => "DEFAULT",
					"given-name" => $result['f_name'],
					"index-to" => $result['zip'],
					"mail-category" => "ORDINARY",
					"mail-direct" => 643, // Russia
					"mail-type" => "PARCEL_CLASS_1",
					"mass" => $total_weight,
					"middle-name" => $result['m_name'],
					"order-num" => $result['order_id'],
					"payment-method" => "CASHLESS",
					"place-to" => $result['city'],
					"region-to" => $region, //
					"sms-notice-recipient"=> 1,
					"street-to" => $result['street'], // Получить улицу из result['street']
					"surname" => $result['l_name'],
					"tel-address" => $phone, //
					"transport-type" => "SURFACE",
                    // габариты временно не указывать
					/*
					"dimension"=> array(
						"height"=> 3,
						"length"=> 9,
						"width"=>73,
					),
					*/
				);

				$json = '['.json_encode($data).']';

				$request = curl_init('https://otpravka-api.pochta.ru/1.0/user/backlog');
				$headers[] = 'Authorization: AccessToken ' . self::POCHTA_TOKEN;
				$headers[] = 'X-User-Authorization: Basic '. self::POCHTA_KEY;
				$headers[] = 'Content-Type: application/json';
				$headers[] = 'Accept: application/json;charset=UTF-8';
				$headers[] = 'Content-Length: ' . strlen($json);
				curl_setopt($request, CURLOPT_POSTFIELDS, $json);
				curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($request, CURLOPT_CUSTOMREQUEST, 'PUT');
				$return = curl_exec($request);
				curl_close($request);
				$result = json_decode($return, true);

				$log = date('d.m.Y H:i:s') . "\n Created order number: " . $order_id . "\n " . print_r($result, TRUE);
				file_put_contents('nw_pochta.log', $log . "\n", FILE_APPEND | LOCK_EX);

				// задаем значение для проверки
				if ($result['result-ids']) {
					//сразу после создания отправления создать запрос на получение информации по этому отправлению
					$shipment_id = $result['result-ids'][0];
					$path = "1.0/backlog/" . $shipment_id;
					$request_b = curl_init('https://otpravka-api.pochta.ru/' . $path);
					$headers_b[] = 'Authorization: AccessToken ' . self::POCHTA_TOKEN;
					$headers_b[] = 'X-User-Authorization: Basic ' . self::POCHTA_KEY;
					$headers_b[] = 'Content-Type: application/json';
					$headers_b[] = 'Accept: application/json;charset=UTF-8';
					curl_setopt($request_b, CURLOPT_HTTPHEADER, $headers_b);
					curl_setopt($request_b, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($request_b, CURLOPT_CUSTOMREQUEST, 'GET');
					$data_by_index = curl_exec($request_b);
					curl_close($request_b);

					$result_b = json_decode($data_by_index, true);

					// если есть данные по трек-номеру, то заполнить доп. поле в заказе
					if ($result_b && isset($result_b['barcode'])) {
						$barcode = $result_b['barcode'];
						$order = new stdClass();
						$order->order_id = $order_id;
						$order->ext_field_3 = $barcode; // сохранить
						JFactory::getDbo()
							->updateObject('#__jshopping_orders', $order, 'order_id');

						$log = date('d.m.Y H:i:s') . "\n Updated order number:  " . $order_id . ' with track number: ' . $barcode .  "\n " . print_r($result_b, TRUE);
						file_put_contents('nw_pochta.log', $log . "\n", FILE_APPEND | LOCK_EX);
					}

				}

			} // end проверка региона
		}
	}

}
