<?php
/**
 * Created by PhpStorm.
 * User: dzf
 * Date: 27.06.2018
 * Time: 18:21
 */


// Initialize Joomla framework
const _JEXEC = 1;

// Load system defines


if (file_exists(dirname(dirname(dirname(__DIR__))) . '/defines.php'))
{
  require_once dirname(dirname(dirname(__DIR__))) . '/defines.php';
}


if (!defined('_JDEFINES'))
{
  define('JPATH_BASE', dirname(dirname(dirname(__DIR__))));

  require_once JPATH_BASE . '/includes/defines.php';
}

// Get the framework.
require_once JPATH_LIBRARIES . '/import.legacy.php';

// Bootstrap the CMS libraries.
require_once JPATH_LIBRARIES . '/cms.php';

// Load the configuration
require_once JPATH_CONFIGURATION . '/configuration.php';

require_once JPATH_BASE . '/includes/framework.php';


//define('POCHTA_KEY','aW5mb0BuZXd3YWxsZXQucnU6ZHg2MGhKTjNrL2R4');

define('POCHTA_KEY', 'aW5mb0BuZXd3YWxsZXQucnU6ZHg2MGhKTjNrL2R4Xw==');
define('POCHTA_TOKEN', 'ArRltqR60XPnCJGYPLeS9r00Ay5lTp4_');

define('POCHTA_ENVELOPE', 0.022);
define('POCHTA_ENVELOPE_TRAVEL_KIT', 0.022);
define('SHIPPING_RU_POST', 2);
define('QUERY_LIMIT', 10);


function createPochtaOrder($order_id) {

  $db = JFactory::getDbo();
  $query = $db->getQuery(TRUE);
  $query->select('*');
  $query->from('#__jshopping_orders');
  $query->where($db->quoteName('order_id') . ' = ' . $order_id);
  // Reset the query using our newly populated query object.
  $db->setQuery($query);

  $result = $db->loadAssoc();

  if (empty($result['ext_field_3'])) {

    //проверить существование заказа с таким номером
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

    // get order_product_items
    $db = JFactory::getDbo();
    $query = $db->getQuery(TRUE);
    $query->select('*');
    $query->from('#__jshopping_order_item');
    $query->where($db->quoteName('order_id') . ' = ' . $order_id);

    $db->setQuery($query);
    $result_goods = $db->loadAssocList();

    $items = '';
    $has_travel_kit = FALSE;
    $total_weight = 0;
    foreach ($result_goods as $good) {
      $amount = (int) $good['product_quantity'];
      // if payment nal - method_id 1  bank - 9
      $payment = $result['payment_method_id'] == 1 ? $good['product_item_price'] : 0;

      $total_weight += $good['weight'];

      if ($good['category_id'] == 10) { // есть товар из категории TravelKit
        $has_travel_kit = TRUE;
      }

    }

    if ($has_travel_kit) {
      $total_weight += POCHTA_ENVELOPE_TRAVEL_KIT;
    }
    else {
      $total_weight += POCHTA_ENVELOPE;
    }

    $total_weight = $total_weight * 1000;

    // подготовить номер телефона
    $phone = str_replace(array(' ', '(', ')', '-'), '', $result['phone']);

    // Получить регион по индексу
    $request_r = curl_init('https://otpravka-api.pochta.ru/postoffice/1.0/' . $result['zip']);
    $headers_r[] = 'Authorization: AccessToken ' . POCHTA_TOKEN;
    $headers_r[] = 'X-User-Authorization: Basic ' . POCHTA_KEY;
    $headers_r[] = 'Content-Type: application/json';
    $headers_r[] = 'Accept: application/json;charset=UTF-8';

    curl_setopt($request_r, CURLOPT_HTTPHEADER, $headers_r);
    curl_setopt($request_r, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($request_r, CURLOPT_CUSTOMREQUEST, 'GET');
    $data_by_index = curl_exec($request_r);
    curl_close($request_r);

    if ($data_by_index) {
      $data_by_index = json_decode($data_by_index);
      $region = $data_by_index->region;
    }

    if ($region) { // проверка региона
      //Данные для отправки
      $data = array(
        "address-type-to" => "DEFAULT",
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
        "street-to" => $result['street'],
        "surname" => $result['l_name'],
        "tel-address" => $phone, //
        "transport-type" => "SURFACE",

      );

      $json = '[' . json_encode($data) . ']';

      $request = curl_init('https://otpravka-api.pochta.ru/1.0/user/backlog');
      $headers[] = 'Authorization: AccessToken ' . POCHTA_TOKEN;
      $headers[] = 'X-User-Authorization: Basic ' . POCHTA_KEY;
      $headers[] = 'Content-Type: application/json';
      $headers[] = 'Accept: application/json;charset=UTF-8';
      $headers[] = 'Content-Length: ' . strlen($json);
      curl_setopt($request, CURLOPT_POSTFIELDS, $json);
      curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($request, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($request, CURLOPT_CUSTOMREQUEST, 'PUT');
      $return = curl_exec($request);

      curl_close($request);

      $result = json_decode($return, TRUE);

      $log = date('d.m.Y H:i:s') . "\n Created order number: " . $order_id . "\n " . print_r($result, TRUE);
      file_put_contents('nw_pochta.log', $log . "\n", FILE_APPEND | LOCK_EX);

      // задаем значение для проверки
      if ($result['result-ids']) {
        //сразу после создания отправления создать запрос на получение информации по этому отправлению
        $shipment_id = $result['result-ids'][0];
        $path = "1.0/backlog/" . $shipment_id;
        $request_b = curl_init('https://otpravka-api.pochta.ru/' . $path);
        $headers_b[] = 'Authorization: AccessToken ' . POCHTA_TOKEN;
        $headers_b[] = 'X-User-Authorization: Basic ' . POCHTA_KEY;
        $headers_b[] = 'Content-Type: application/json';
        $headers_b[] = 'Accept: application/json;charset=UTF-8';
        curl_setopt($request_b, CURLOPT_HTTPHEADER, $headers_b);
        curl_setopt($request_b, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($request_b, CURLOPT_CUSTOMREQUEST, 'GET');
        $data_by_index = curl_exec($request_b);
        curl_close($request_b);

        $result_b = json_decode($data_by_index, true);

        if ($result_b && isset($result_b['barcode'])) {
          $barcode = $result_b['barcode'];
          $order = new stdClass();
          $order->order_id = $order_id;
          $order->ext_field_3 = $barcode; // сохранить
          $result_updated = JFactory::getDbo()
            ->updateObject('#__jshopping_orders', $order, 'order_id');

          $log = date('d.m.Y H:i:s') . "\n Updated order number:  " . $order_id . ' with track number: ' . $barcode .  "\n " . print_r($result_b, TRUE);
          file_put_contents('nw_pochta.log', $log . "\n", FILE_APPEND | LOCK_EX);
        }

      } // end проверка региона


    }

  }
}


$where = "shipping_method_id = 2 AND order_status = 6 AND ext_field_3 <> ''";

$db = JFactory::getDbo();
$query = $db->getQuery(TRUE);
$query->select('*');
$query->from('#__jshopping_orders');
$query->where($where);
$query->order('order_id DESC');
$query->setLimit(QUERY_LIMIT);

// Reset the query using our newly populated query object.
$db->setQuery($query);

$orders = $db->loadAssocList();

// если в выборке есть заказы
if ($orders) {
  foreach ($orders as $order) {
    createPochtaOrder($order['order_id']);
  }
}


