<?php
/**
 * @package Joomla.System
 * @version 1.0.0
 * @author Dmitry (Dmitry Zadorozhny)
 * @website https://newwallet.ru
 */

defined('_JEXEC') or die('Restricted access');
jimport('joomla.application.component.controller');

class plgSystemNewwallet_Sort extends JPlugin {

	/**
	 * Ajax handler for sorting
	 */
	public static function onAjaxNewwallet_Sort() {
		jimport('joomla.application.module.helper'); //подключаем хелпер для модуля

		$prod_pr_id = $_POST['prod_pr_id'];
		$sort_value = $_POST['sort_value'];

		$product_id = substr($prod_pr_id, 3);

		$object = new stdClass();

		$object->product_id = $product_id;
		$object->product_ordering = $sort_value;

		// Обновить значение в таблицы товаров
		$result = JFactory::getDbo()->updateObject('#__jshopping_products_to_categories', $object, 'product_id');

		if ($result) {
			return TRUE;
		}
		return FALSE;

	}

 /**
  * Attach plugin script for admin pages
  */
  public function onBeforeCompileHead() {
		$document = JFactory::getDocument();
		$headData = $document->getHeadData();
		$scripts = &$headData['scripts'];

		// удалить скрипт из массива в админке
		unset($scripts['/media/k2/assets/js/k2.frontend.js?v=2.8.0&amp;sitepath=/']);
		$headData['scripts'] = $scripts;
		$document->setHeadData($headData);

    // Проверить это это страницы админки
    $app = JFactory::getApplication();
    if ($app->isSite()) {
        return;
    }

    $document = JFactory::getDocument();

    // подключить скрипт для сортировки
    $document->addScript(JUri::root().'plugins/system/newwallet_sort/js/sort.js' );
	}

}
