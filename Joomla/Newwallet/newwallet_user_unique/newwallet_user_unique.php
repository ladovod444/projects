<?php
/**
 * @package Joomla.JoomShopping.Products
 * @version 1.0.0
 * @author Dmitry (Dmitry Zadorozhny)
 * @website https://newwallet.ru
 */
defined('_JEXEC') or die('Restricted access');
jimport('joomla.application.component.controller');

class plgJshoppingCheckoutNewwallet_User_Unique extends JPlugin{
	const DAYS_EXPIRE = 30;

  /*
   * After order create full event
   */
  function onAfterCreateOrderFull($order, $cart) {

    // получить значения из настройек плагина
    $coupon_month_expire = $this->params->get('coupon_month_expire');
    $coupon_percent_value = $this->params->get('coupon_percent_value');

    $day_expire = time() + $coupon_month_expire * self::DAYS_EXPIRE * 24 * 3600;
    $user_id = $order->user_id;
    $this->createJoomshoppingCoupon($coupon_percent_value, $user_id, $day_expire, $coupon_month_expire);
  }

  /*
   * Create coupon for user
   */
  function createJoomshoppingCoupon($coupon_value = 10, $user_id, $day_expire, $coupon_month_expire) {
    $item = new stdClass();
    $item->coupon_type = 0;
    $item->coupon_code = $this->generateNumberCouponCode();
    $item->coupon_value = $coupon_value;
    $item->tax_id = 0;
    $item->used = 0;
    $item->for_user_id = 0;
    $item->coupon_start_date = date('Y-m-d', time());
    $item->coupon_expire_date = date('Y-m-d', $day_expire); // истекает поcле заданного периода
    $item->finished_after_used = 1; // 1 - означает, что купон можно использовать 1 раз
    $item->coupon_publish = 1;

    $result = JFactory::getDbo()->insertObject('#__jshopping_coupons', $item);

    if ($result) {
      $item_u = new stdClass();
      $item_u->user_id = $user_id;
      $item_u->created = time();
      $item_u->coupon_code = $item->coupon_code;
      $item_u->coupon_value = $item->coupon_value;
      $item_u->coupon_month_expire = $coupon_month_expire;
      JFactory::getDbo()->insertObject('unique_coupons', $item_u);
    }
  }

  function generateNumberCouponCode() {
    return 'U' . time();
  }

}
