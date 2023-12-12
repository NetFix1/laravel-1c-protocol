<?php
/**
 * ExportOrdersSelf.php
 * Date: 24.06.2020
 * Time: 18:29
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Interfaces;


interface ExportOrdersSelf extends ExportSuccess
{
    /**
     * Формирование данных для 1С о заказа в интернет-магазине
     * @return mixed
     */
    public function getXML();
}
